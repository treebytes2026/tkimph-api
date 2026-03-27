<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\MenuItemReview;
use App\Models\Order;
use App\Models\OrderIssue;
use App\Models\OrderReview;
use App\Models\OrderReviewReport;
use App\Models\Restaurant;
use App\Models\User;
use App\Notifications\AdminSystemNotification;
use App\Notifications\NewPartnerOrderNotification;
use App\Support\OrderWorkflow;
use App\Support\PromotionEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerOrderController extends Controller
{
    private const SERVICE_FEE = 5.0;
    private const DELIVERY_FEE = 0.0;

    public function index(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        $orders = Order::query()
            ->with([
                'items.menuItem:id,image_path',
                'restaurant:id,name,slug,address,profile_image_path',
                'discounts',
                'issues',
                'review',
            ])
            ->where('customer_id', $customer->id)
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 10));

        return response()->json([
            'data' => $orders->getCollection()->map(fn (Order $order) => $this->serializeOrder($order))->values()->all(),
            'current_page' => $orders->currentPage(),
            'last_page' => $orders->lastPage(),
            'per_page' => $orders->perPage(),
            'total' => $orders->total(),
        ]);
    }

    public function validatePromotion(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'restaurant_id' => ['nullable', 'integer', 'exists:restaurants,id'],
        ]);

        $restaurant = null;
        if (! empty($data['restaurant_id'])) {
            $restaurant = Restaurant::query()->whereKey((int) $data['restaurant_id'])->first();
        }
        $result = PromotionEngine::evaluateCode($data['code'], (float) $data['subtotal'], $customer, $restaurant);

        return response()->json([
            'valid' => $result['valid'],
            'code' => $result['code'],
            'discount_amount' => (float) $result['discount_amount'],
            'audit_meta' => $result['audit_meta'],
            'invalid_reasons' => $result['invalid_reasons'] ?? [],
            'message' => $result['valid'] ? 'Promo code applied.' : 'Promo code is not eligible.',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        $data = $request->validate([
            'restaurant_id' => ['required', 'integer', 'exists:restaurants,id'],
            'delivery_mode' => ['required', 'in:delivery,pickup'],
            'payment_method' => ['required', 'in:cod,wallet,card'],
            'promo_code' => ['nullable', 'string', 'max:40'],
            'promo_codes' => ['nullable', 'array', 'max:5'],
            'promo_codes.*' => ['string', 'max:40'],
            'delivery_address' => ['required_if:delivery_mode,delivery', 'nullable', 'string', 'max:2000'],
            'delivery_floor' => ['nullable', 'string', 'max:120'],
            'delivery_note' => ['nullable', 'string', 'max:1000'],
            'location_label' => ['nullable', 'string', 'max:40'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', 'exists:menu_items,id'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        $restaurant = Restaurant::query()
            ->whereKey($data['restaurant_id'])
            ->where('is_active', true)
            ->first();

        if (! $restaurant || ! $restaurant->isOperationallyAvailable()) {
            throw ValidationException::withMessages([
                'restaurant_id' => ['Restaurant is not available.'],
            ]);
        }

        $itemIds = collect($data['items'])->pluck('item_id')->values()->all();
        $itemsById = MenuItem::query()
            ->whereIn('id', $itemIds)
            ->where('is_available', true)
            ->whereHas('menu', static fn ($q) => $q->where('restaurant_id', $restaurant->id)->where('is_active', true))
            ->get()
            ->keyBy('id');

        if ($itemsById->count() !== count($itemIds)) {
            throw ValidationException::withMessages([
                'items' => ['One or more items are unavailable for this restaurant.'],
            ]);
        }

        $created = DB::transaction(function () use ($data, $customer, $restaurant, $itemsById) {
            $subtotal = 0.0;
            $lineRows = [];

            foreach ($data['items'] as $line) {
                $menuItem = $itemsById->get((int) $line['item_id']);
                $qty = (int) $line['qty'];
                $unitPrice = (float) $menuItem->price;
                $lineTotal = $qty * $unitPrice;
                $subtotal += $lineTotal;

                $lineRows[] = [
                    'menu_item_id' => $menuItem->id,
                    'name' => $menuItem->name,
                    'unit_price' => $unitPrice,
                    'quantity' => $qty,
                    'line_total' => $lineTotal,
                ];
            }

            $codes = collect($data['promo_codes'] ?? [])
                ->map(fn ($code) => (string) $code)
                ->filter()
                ->values();
            if (! empty($data['promo_code'])) {
                $codes->prepend((string) $data['promo_code']);
            }
            $promoResult = PromotionEngine::evaluatePromotions($codes->all(), $subtotal, $customer, $restaurant, true);

            if ($codes->isNotEmpty() && ! $promoResult['valid']) {
                throw ValidationException::withMessages([
                    'promo_code' => ['Promo code is invalid or no longer eligible for this order.'],
                ]);
            }

            $discountAmount = (float) $promoResult['discount_amount'];
            $serviceFee = self::SERVICE_FEE;
            $deliveryFee = $data['delivery_mode'] === 'delivery' ? self::DELIVERY_FEE : 0.0;
            $settlement = OrderWorkflow::settlementFields($subtotal, $serviceFee, $deliveryFee);
            $total = max(0, $subtotal + $serviceFee + $deliveryFee - $discountAmount);

            $paymentMethod = $data['payment_method'];
            $paymentStatus = $paymentMethod === 'cod' ? 'unpaid' : 'paid';

            $order = Order::query()->create([
                'order_number' => $this->nextOrderNumber(),
                'customer_id' => $customer->id,
                'restaurant_id' => $restaurant->id,
                'status' => Order::STATUS_PENDING,
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentStatus,
                'refund_status' => Order::REFUND_STATUS_NOT_REQUIRED,
                'delivery_mode' => $data['delivery_mode'],
                'delivery_address' => (string) ($data['delivery_address'] ?? ''),
                'delivery_floor' => $data['delivery_floor'] ?? null,
                'delivery_note' => $data['delivery_note'] ?? null,
                'location_label' => $data['location_label'] ?? null,
                'subtotal' => $subtotal,
                'service_fee' => $serviceFee,
                'delivery_fee' => $settlement['delivery_fee'],
                'discounts_total' => $discountAmount,
                'gross_sales' => $settlement['gross_sales'],
                'restaurant_net' => $settlement['restaurant_net'],
                'total' => $total,
                'placed_at' => now(),
            ]);

            $order->items()->createMany($lineRows);

            if ($promoResult['valid'] && ! empty($promoResult['applied_promotions'])) {
                foreach ($promoResult['applied_promotions'] as $appliedPromotion) {
                    $promotion = $appliedPromotion['promotion'];
                    $discountForPromotion = (float) $appliedPromotion['discount_amount'];
                $order->discounts()->create([
                    'promotion_id' => $promotion->id,
                    'code' => $promotion->code,
                    'discount_type' => $promotion->discount_type,
                    'discount_value' => (float) $promotion->discount_value,
                        'discount_amount' => $discountForPromotion,
                        'audit_meta' => $appliedPromotion['audit_meta'] ?? [],
                ]);
                $promotion->redemptions()->create([
                    'user_id' => $customer->id,
                    'order_id' => $order->id,
                        'discount_amount' => $discountForPromotion,
                    'subtotal_at_apply' => $subtotal,
                ]);
                OrderWorkflow::recordEvent(
                    $order,
                    'order_discount_applied',
                    $customer,
                    null,
                    Order::STATUS_PENDING,
                    'Promo code '.$promotion->code.' applied',
                    [
                        'code' => $promotion->code,
                            'discount_amount' => $discountForPromotion,
                    ]
                );
                }
            }

            OrderWorkflow::recordEvent($order, 'order_created', $customer, null, Order::STATUS_PENDING, 'Order placed by customer');

            $owner = $restaurant->owner;
            if ($owner) {
                $owner->notify(new NewPartnerOrderNotification($order));
            }

            User::query()
                ->admins()
                ->each(fn (User $admin) => $admin->notify(new AdminSystemNotification(
                    'new_order',
                    'New order '.$order->order_number.' needs monitoring.',
                    [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'restaurant_id' => $restaurant->id,
                        'restaurant_name' => $restaurant->name,
                    ]
                )));

            return $order->load([
                'items.menuItem:id,image_path',
                'restaurant:id,name,slug,address,profile_image_path',
                'discounts',
                'issues',
                'review',
            ]);
        });

        return response()->json([
            'message' => 'Order placed successfully.',
            'order' => $this->serializeOrder($created),
        ], 201);
    }

    public function requestCancel(Request $request, Order $order): JsonResponse
    {
        $customer = $this->customer($request);
        $this->assertOwnedOrder($order, $customer);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if (! OrderWorkflow::customerMayRequestCancellation($order)) {
            abort(422, 'This order is outside the cancellation request window. Please create a help dispute.');
        }

        $order->update([
            'customer_cancel_requested_at' => now(),
            'customer_cancel_reason' => $data['reason'],
        ]);

        $issue = OrderWorkflow::createOrderIssue(
            $order,
            $customer,
            OrderIssue::TYPE_CANCEL_REQUEST,
            'Customer cancellation request',
            $data['reason']
        );

        OrderWorkflow::recordEvent(
            $order,
            'customer_cancel_requested',
            $customer,
            $order->status,
            $order->status,
            $data['reason'],
            ['issue_id' => $issue->id]
        );

        return response()->json([
            'message' => 'Cancellation request submitted.',
            'issue' => $this->serializeIssue($issue),
            'order' => $this->serializeOrder($order->fresh()->load(['items.menuItem:id,image_path', 'restaurant:id,name,slug,address,profile_image_path', 'discounts', 'issues', 'review'])),
        ]);
    }

    public function storeIssue(Request $request, Order $order): JsonResponse
    {
        $customer = $this->customer($request);
        $this->assertOwnedOrder($order, $customer);

        $data = $request->validate([
            'issue_type' => ['required', 'in:refund_request,dispute,help'],
            'subject' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:4000'],
        ]);

        if ($data['issue_type'] === OrderIssue::TYPE_REFUND_REQUEST) {
            if (! in_array($order->status, [Order::STATUS_CANCELLED, Order::STATUS_FAILED, Order::STATUS_UNDELIVERABLE], true)) {
                throw ValidationException::withMessages([
                    'issue_type' => ['Refund requests are available only for failed/cancelled/undeliverable orders.'],
                ]);
            }
            if ($order->payment_status !== 'paid') {
                throw ValidationException::withMessages([
                    'issue_type' => ['Refund requests are only available for paid orders.'],
                ]);
            }
        }

        $issue = OrderWorkflow::createOrderIssue(
            $order,
            $customer,
            $data['issue_type'],
            $data['subject'],
            $data['description']
        );

        if ($data['issue_type'] === OrderIssue::TYPE_REFUND_REQUEST && $order->refund_status === Order::REFUND_STATUS_NOT_REQUIRED) {
            $order->update([
                'refund_status' => Order::REFUND_STATUS_PENDING,
                'refund_requested_at' => now(),
                'refund_reason' => $data['description'],
            ]);
        }

        OrderWorkflow::recordEvent(
            $order,
            'customer_issue_created',
            $customer,
            $order->status,
            $order->status,
            $data['subject'],
            ['issue_id' => $issue->id, 'issue_type' => $data['issue_type']]
        );

        return response()->json([
            'message' => 'Issue submitted successfully.',
            'issue' => $this->serializeIssue($issue),
        ], 201);
    }

    public function storeReview(Request $request, Order $order): JsonResponse
    {
        $customer = $this->customer($request);
        $this->assertOwnedOrder($order, $customer);

        if ($order->status !== Order::STATUS_COMPLETED) {
            abort(422, 'You can review only completed orders.');
        }

        $data = $request->validate([
            'restaurant_rating' => ['required', 'integer', 'min:1', 'max:5'],
            'rider_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $review = OrderReview::query()->updateOrCreate(
            ['order_id' => $order->id],
            [
                'customer_id' => $customer->id,
                'restaurant_id' => $order->restaurant_id,
                'rider_id' => $order->rider_id,
                'restaurant_rating' => $data['restaurant_rating'],
                'rider_rating' => $data['rider_rating'] ?? null,
                'comment' => $data['comment'] ?? null,
                'status' => OrderReview::STATUS_PUBLISHED,
            ]
        );

        OrderWorkflow::recordEvent(
            $order,
            'customer_review_submitted',
            $customer,
            $order->status,
            $order->status,
            'Review submitted',
            ['review_id' => $review->id]
        );

        return response()->json([
            'message' => 'Review submitted.',
            'review' => $this->serializeReview($review),
        ], 201);
    }

    public function storeItemReview(Request $request, Order $order): JsonResponse
    {
        $customer = $this->customer($request);
        $this->assertOwnedOrder($order, $customer);

        if ($order->status !== Order::STATUS_COMPLETED) {
            abort(422, 'You can review dishes only for completed orders.');
        }

        $data = $request->validate([
            'menu_item_id' => ['required', 'integer'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $itemExistsOnOrder = $order->items()->where('menu_item_id', $data['menu_item_id'])->exists();
        if (! $itemExistsOnOrder) {
            abort(422, 'Selected item is not part of this order.');
        }

        $review = MenuItemReview::query()->updateOrCreate(
            [
                'order_id' => $order->id,
                'customer_id' => $customer->id,
                'menu_item_id' => $data['menu_item_id'],
            ],
            [
                'restaurant_id' => $order->restaurant_id,
                'rating' => $data['rating'],
                'comment' => $data['comment'] ?? null,
                'status' => MenuItemReview::STATUS_PUBLISHED,
            ]
        );

        OrderWorkflow::recordEvent(
            $order,
            'customer_menu_item_review_submitted',
            $customer,
            $order->status,
            $order->status,
            'Dish review submitted',
            ['menu_item_id' => $data['menu_item_id'], 'menu_item_review_id' => $review->id]
        );

        return response()->json([
            'message' => 'Dish review submitted.',
            'review' => [
                'id' => $review->id,
                'menu_item_id' => $review->menu_item_id,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'status' => $review->status,
                'created_at' => optional($review->created_at)->toIso8601String(),
            ],
        ], 201);
    }

    public function reportReview(Request $request, OrderReview $review): JsonResponse
    {
        $customer = $this->customer($request);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:120'],
            'details' => ['nullable', 'string', 'max:1000'],
        ]);

        $report = OrderReviewReport::query()->updateOrCreate(
            [
                'order_review_id' => $review->id,
                'reported_by_user_id' => $customer->id,
            ],
            [
                'reason' => $data['reason'],
                'details' => $data['details'] ?? null,
                'status' => OrderReviewReport::STATUS_OPEN,
                'resolved_by_admin_id' => null,
                'resolved_at' => null,
            ]
        );

        return response()->json([
            'message' => 'Report submitted for moderation.',
            'report_id' => $report->id,
        ], 201);
    }

    private function customer(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user || ! $user->isCustomer()) {
            abort(403, 'Customer access required.');
        }

        return $user;
    }

    private function assertOwnedOrder(Order $order, User $customer): void
    {
        abort_unless($order->customer_id === $customer->id, 403, 'You do not have access to this order.');
    }

    private function serializeOrder(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'refund_status' => $order->refund_status,
            'refund_requested_at' => optional($order->refund_requested_at)->toIso8601String(),
            'refunded_at' => optional($order->refunded_at)->toIso8601String(),
            'refund_reference' => $order->refund_reference,
            'refund_reason' => $order->refund_reason,
            'delivery_mode' => $order->delivery_mode,
            'delivery_address' => $order->delivery_address,
            'delivery_floor' => $order->delivery_floor,
            'delivery_note' => $order->delivery_note,
            'location_label' => $order->location_label,
            'subtotal' => (float) $order->subtotal,
            'service_fee' => (float) $order->service_fee,
            'delivery_fee' => (float) $order->delivery_fee,
            'discounts_total' => (float) $order->discounts_total,
            'total' => (float) $order->total,
            'placed_at' => optional($order->placed_at)->toIso8601String(),
            'customer_cancel_requested_at' => optional($order->customer_cancel_requested_at)->toIso8601String(),
            'customer_cancel_reason' => $order->customer_cancel_reason,
            'customer_cancel_eligible' => OrderWorkflow::customerMayRequestCancellation($order),
            'restaurant' => $order->restaurant ? [
                'id' => $order->restaurant->id,
                'name' => $order->restaurant->name,
                'slug' => $order->restaurant->slug,
                'address' => $order->restaurant->address,
                'profile_image_path' => $order->restaurant->profile_image_path,
                'profile_image_url' => $order->restaurant->profile_image_path
                    ? Storage::disk('public')->url($order->restaurant->profile_image_path)
                    : null,
            ] : null,
            'items' => $order->items->map(static fn ($item) => [
                'id' => $item->id,
                'menu_item_id' => $item->menu_item_id,
                'name' => $item->name,
                'unit_price' => (float) $item->unit_price,
                'quantity' => $item->quantity,
                'line_total' => (float) $item->line_total,
                'image_path' => $item->menuItem?->image_path,
                'image_url' => $item->menuItem?->image_url,
            ])->values()->all(),
            'discounts' => $order->relationLoaded('discounts')
                ? $order->discounts->map(fn ($discount) => [
                    'id' => $discount->id,
                    'code' => $discount->code,
                    'discount_type' => $discount->discount_type,
                    'discount_value' => (float) $discount->discount_value,
                    'discount_amount' => (float) $discount->discount_amount,
                    'audit_meta' => $discount->audit_meta,
                ])->values()->all()
                : [],
            'issues' => $order->relationLoaded('issues')
                ? $order->issues->map(fn (OrderIssue $issue) => $this->serializeIssue($issue))->values()->all()
                : [],
            'review' => $order->relationLoaded('review') && $order->review
                ? $this->serializeReview($order->review)
                : null,
        ];
    }

    private function serializeIssue(OrderIssue $issue): array
    {
        return [
            'id' => $issue->id,
            'issue_type' => $issue->issue_type,
            'status' => $issue->status,
            'subject' => $issue->subject,
            'description' => $issue->description,
            'resolution' => $issue->resolution,
            'created_at' => optional($issue->created_at)->toIso8601String(),
            'resolved_at' => optional($issue->resolved_at)->toIso8601String(),
        ];
    }

    private function serializeReview(OrderReview $review): array
    {
        return [
            'id' => $review->id,
            'restaurant_rating' => $review->restaurant_rating,
            'rider_rating' => $review->rider_rating,
            'comment' => $review->comment,
            'status' => $review->status,
            'created_at' => optional($review->created_at)->toIso8601String(),
        ];
    }

    private function nextOrderNumber(): string
    {
        return 'TKM-' . now()->format('Ymd-His') . '-' . Str::upper(Str::random(5));
    }
}
