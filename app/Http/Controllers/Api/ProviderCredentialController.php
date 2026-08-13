<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditEntry;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Music\Admin\ProviderCredentialResolver;
use App\Music\Admin\ProviderCredentialTester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProviderCredentialController extends Controller
{
    public function index(Request $request, ProviderCredentialResolver $resolver): JsonResponse
    {
        $this->owner($request);

        return $this->response($resolver->statuses());
    }

    public function update(
        Request $request,
        string $provider,
        ProviderCredentialResolver $resolver,
        ProviderCredentialTester $tester,
    ): JsonResponse {
        $owner = $this->owner($request);
        $this->provider($provider, $resolver);
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'secret' => [
                'required',
                'string',
                'min:1',
                'max:1024',
                function (string $attribute, mixed $value, callable $fail): void {
                    if (is_string($value) && preg_match('/[\x00-\x1F\x7F-\x9F]/', $value)) {
                        $fail("The {$attribute} field must not contain control characters.");
                    }
                },
            ],
        ]);
        $this->confirmPassword($owner, $validated['current_password']);

        try {
            $tester->test($provider, $validated['secret']);
        } catch (Throwable $exception) {
            Log::warning('Provider credential test failed.', [
                'provider' => $provider,
                'error_code' => class_basename($exception),
            ]);
            throw ValidationException::withMessages([
                'secret' => ['The provider rejected the submitted credential.'],
            ]);
        }

        DB::transaction(function () use ($owner, $provider, $validated): void {
            ProviderCredential::query()->updateOrCreate(
                ['provider' => $provider],
                [
                    'credentials' => [$this->credentialKey($provider) => $validated['secret']],
                    'tested_at' => now(),
                ],
            );
            AdminAuditEntry::query()->create([
                'owner_user_id' => $owner->id,
                'action' => 'credential_activated',
                'subject' => $provider,
                'context' => ['source' => 'database'],
            ]);
        });

        return $this->response($this->present($resolver->status($provider)));
    }

    public function destroy(Request $request, string $provider, ProviderCredentialResolver $resolver): JsonResponse
    {
        $owner = $this->owner($request);
        $this->provider($provider, $resolver);
        $validated = $request->validate(['current_password' => ['required', 'string']]);
        $this->confirmPassword($owner, $validated['current_password']);

        DB::transaction(function () use ($owner, $provider): void {
            ProviderCredential::query()->whereKey($provider)->delete();
            AdminAuditEntry::query()->create([
                'owner_user_id' => $owner->id,
                'action' => 'credential_removed',
                'subject' => $provider,
                'context' => (object) [],
            ]);
        });

        return $this->response($this->present($resolver->status($provider)));
    }

    private function owner(Request $request): User
    {
        $user = $request->user();
        abort_if(! $user instanceof User, 401);
        abort_unless($user->is_owner, 403);

        return $user;
    }

    private function provider(string $provider, ProviderCredentialResolver $resolver): void
    {
        abort_unless($resolver->supports($provider), 404);
    }

    private function confirmPassword(User $owner, string $password): void
    {
        if (! Hash::check($password, $owner->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }
    }

    private function credentialKey(string $provider): string
    {
        return $provider === 'theaudiodb' ? 'api_key' : 'token';
    }

    /** @param array<string, mixed> $status
     * @return array{provider:string,configured:bool,source:string,tested_at:?string}
     */
    private function present(array $status): array
    {
        return [
            'provider' => $status['provider'],
            'configured' => $status['configured'],
            'source' => $status['source'],
            'tested_at' => $status['tested_at']?->toAtomString(),
        ];
    }

    private function response(array $data): JsonResponse
    {
        if (array_is_list($data)) {
            $data = array_map(fn (array $status): array => $this->present($status), $data);
        }

        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }
}
