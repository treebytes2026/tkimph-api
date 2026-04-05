<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\AdminSystemNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class CustomerAccountController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $this->customer($request);

        return response()->json($this->profilePayload($user));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $this->customer($request);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . $user->id,
            'phone' => 'required|string|max:30',
            'address' => 'nullable|string|max:1000',
        ]);

        $emailChanged = $data['email'] !== $user->email;
        $phoneChanged = $data['phone'] !== ($user->phone ?? '');

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->phone = $data['phone'];
        $user->address = $data['address'] ?? null;

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        if ($phoneChanged) {
            $user->phone_verified_at = null;
        }

        $user->save();

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => $this->profilePayload($user),
        ]);
    }

    public function sendEmailVerificationCode(Request $request): JsonResponse
    {
        $user = $this->customer($request);
        $code = $this->generateCode();

        $user->email_verification_code = Hash::make($code);
        $user->email_verification_code_expires_at = now()->addMinutes(10);
        $user->save();

        Mail::raw(
            "Your TKimph email verification code is: {$code}. This code expires in 10 minutes.",
            fn ($message) => $message->to($user->email)->subject('TKimph Email Verification Code')
        );

        return response()->json([
            'message' => 'Verification code sent to your email.',
        ]);
    }

    public function verifyEmailCode(Request $request): JsonResponse
    {
        $user = $this->customer($request);
        $data = $request->validate([
            'code' => 'required|digits:6',
        ]);

        if (
            ! $user->email_verification_code ||
            ! $user->email_verification_code_expires_at ||
            Carbon::parse($user->email_verification_code_expires_at)->isPast() ||
            ! Hash::check($data['code'], $user->email_verification_code)
        ) {
            throw ValidationException::withMessages([
                'code' => ['Invalid or expired verification code.'],
            ]);
        }

        $user->email_verified_at = now();
        $user->email_verification_code = null;
        $user->email_verification_code_expires_at = null;
        $user->save();

        return response()->json([
            'message' => 'Email verified successfully.',
            'user' => $this->profilePayload($user),
        ]);
    }

    public function sendPhoneVerificationCode(Request $request): JsonResponse
    {
        $user = $this->customer($request);

        if (! $user->phone) {
            throw ValidationException::withMessages([
                'phone' => ['Please add your phone number first.'],
            ]);
        }

        $code = $this->generateCode();
        $user->phone_verification_code = Hash::make($code);
        $user->phone_verification_code_expires_at = now()->addMinutes(10);
        $user->save();

        Mail::raw(
            "Your TKimph phone verification code is: {$code}. This code expires in 10 minutes.",
            fn ($message) => $message->to($user->email)->subject('TKimph Phone Verification Code')
        );

        return response()->json([
            'message' => 'Phone verification code sent to your email.',
        ]);
    }

    public function verifyPhoneCode(Request $request): JsonResponse
    {
        $user = $this->customer($request);
        $data = $request->validate([
            'code' => 'required|digits:6',
        ]);

        if (
            ! $user->phone_verification_code ||
            ! $user->phone_verification_code_expires_at ||
            Carbon::parse($user->phone_verification_code_expires_at)->isPast() ||
            ! Hash::check($data['code'], $user->phone_verification_code)
        ) {
            throw ValidationException::withMessages([
                'code' => ['Invalid or expired verification code.'],
            ]);
        }

        $user->phone_verified_at = now();
        $user->phone_verification_code = null;
        $user->phone_verification_code_expires_at = null;
        $user->save();

        return response()->json([
            'message' => 'Phone verified successfully.',
            'user' => $this->profilePayload($user),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $this->customer($request);
        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->password = $data['password'];
        $user->save();

        return response()->json([
            'message' => 'Password changed successfully.',
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $this->customer($request);
        $data = $request->validate([
            'password' => 'required|string',
        ]);

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Password is incorrect.'],
            ]);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json([
            'message' => 'Account deleted successfully.',
        ]);
    }

    public function submitHelpCenterConcern(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:3000'],
        ]);

        User::query()
            ->admins()
            ->each(fn (User $admin) => $admin->notify(new AdminSystemNotification(
                'customer_help_center',
                'Help center concern from '.$customer->name.': '.$data['subject'],
                [
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->name,
                    'customer_email' => $customer->email,
                    'customer_phone' => $customer->phone,
                    'subject' => $data['subject'],
                    'message_body' => $data['message'],
                ]
            )));

        return response()->json([
            'message' => 'Your concern was sent to admin support.',
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

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function profilePayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'address' => $user->address,
            'email_verified' => (bool) $user->email_verified_at,
            'phone_verified' => (bool) $user->phone_verified_at,
            'email_verified_at' => $user->email_verified_at,
            'phone_verified_at' => $user->phone_verified_at,
        ];
    }
}
