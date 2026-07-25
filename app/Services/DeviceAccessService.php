<?php

namespace App\Services;

use App\Models\AuthorizedDevice;
use App\Models\OperationalSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DeviceAccessService
{
    public const COOKIE_NAME = 'tiketi_device';

    public function isEnabled(string $channel): bool
    {
        if (! Schema::hasTable('operational_settings')) {
            return false;
        }

        $settings = OperationalSetting::current()->settings ?? [];

        return (bool) data_get($settings, "device_restrictions.{$channel}", false);
    }

    public function setEnabled(string $channel, bool $enabled): void
    {
        $operational = OperationalSetting::current();
        $settings = $operational->settings ?? [];
        data_set($settings, "device_restrictions.{$channel}", $enabled);
        $operational->update(['settings' => $settings]);
    }

    /**
     * @return array{id: string, secret: string}
     */
    public function webIdentity(Request $request): array
    {
        $identity = $this->parseIdentity($request->cookie(self::COOKIE_NAME));

        if ($identity) {
            return $identity;
        }

        $identity = [
            'id' => (string) Str::uuid(),
            'secret' => bin2hex(random_bytes(32)),
        ];

        Cookie::queue(cookie(
            self::COOKIE_NAME,
            $identity['id'].'.'.$identity['secret'],
            60 * 24 * 365 * 2,
            '/',
            null,
            $request->isSecure() || app()->environment('production'),
            true,
            false,
            'lax',
        ));

        return $identity;
    }

    public function findApprovedWebDevice(Request $request): ?AuthorizedDevice
    {
        $identity = $this->parseIdentity($request->cookie(self::COOKIE_NAME));

        return $identity
            ? $this->findApproved(AuthorizedDevice::CHANNEL_WEB, $identity['id'], $identity['secret'], $request)
            : null;
    }

    public function requestWebDevice(Request $request, Model $principal): AuthorizedDevice
    {
        $identity = $this->webIdentity($request);

        return $this->requestDevice(
            AuthorizedDevice::CHANNEL_WEB,
            $identity['id'],
            $identity['secret'],
            $principal,
            $request,
            $request->userAgent() ?: 'Navigateur TIKETI',
            $this->browserPlatform($request->userAgent()),
        );
    }

    public function requestControlDevice(
        Request $request,
        Model $principal,
        string $id,
        string $secret,
        ?string $name,
        ?string $platform,
        ?string $appVersion,
    ): AuthorizedDevice {
        return $this->requestDevice(
            AuthorizedDevice::CHANNEL_CONTROL,
            $id,
            $secret,
            $principal,
            $request,
            $name ?: 'TIKETI Control',
            $platform,
            $appVersion,
        );
    }

    public function findApproved(
        string $channel,
        string $id,
        string $secret,
        Request $request,
    ): ?AuthorizedDevice {
        if (! Schema::hasTable('authorized_devices')) {
            return null;
        }

        $device = AuthorizedDevice::query()
            ->whereKey($id)
            ->where('channel', $channel)
            ->first();

        if (! $device || ! hash_equals($device->secret_hash, hash('sha256', $secret)) || ! $device->isApproved()) {
            return null;
        }

        if (! $device->last_seen_at || $device->last_seen_at->lt(now()->subMinutes(5))) {
            $device->forceFill([
                'last_seen_at' => now(),
                'last_ip' => $request->ip(),
                'last_user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            ])->save();
        }

        return $device;
    }

    public function approve(AuthorizedDevice $device, Model $admin): void
    {
        $device->update([
            'status' => AuthorizedDevice::STATUS_APPROVED,
            'approved_by_user_id' => $admin->getKey(),
            'approved_at' => now(),
            'revoked_at' => null,
        ]);
    }

    public function revoke(AuthorizedDevice $device): void
    {
        $device->update([
            'status' => AuthorizedDevice::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);

        if (Schema::hasColumn('personal_access_tokens', 'authorized_device_id')) {
            $device->newQuery()
                ->getConnection()
                ->table('personal_access_tokens')
                ->where('authorized_device_id', $device->id)
                ->delete();
        }
    }

    private function requestDevice(
        string $channel,
        string $id,
        string $secret,
        Model $principal,
        Request $request,
        ?string $name,
        ?string $platform,
        ?string $appVersion = null,
    ): AuthorizedDevice {
        $hash = hash('sha256', $secret);
        $device = AuthorizedDevice::query()->find($id);

        if ($device && (! hash_equals($device->secret_hash, $hash) || $device->channel !== $channel)) {
            abort(403, 'Identité de l’appareil invalide.');
        }

        $attributes = [
            'name' => Str::limit(trim((string) $name), 255, ''),
            'platform' => Str::limit(trim((string) $platform), 255, ''),
            'app_version' => Str::limit(trim((string) $appVersion), 255, ''),
            'requested_by_type' => class_basename($principal),
            'requested_by_id' => $principal->getKey(),
            'last_ip' => $request->ip(),
            'last_user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            'last_seen_at' => now(),
        ];

        if (! $device) {
            return AuthorizedDevice::query()->create([
                'id' => $id,
                'secret_hash' => $hash,
                'channel' => $channel,
                'status' => AuthorizedDevice::STATUS_PENDING,
                'requested_at' => now(),
                ...$attributes,
            ]);
        }

        $device->update($attributes);

        return $device;
    }

    /**
     * @return array{id: string, secret: string}|null
     */
    private function parseIdentity(?string $value): ?array
    {
        if (! $value || ! str_contains($value, '.')) {
            return null;
        }

        [$id, $secret] = explode('.', $value, 2);

        return Str::isUuid($id) && preg_match('/^[a-f0-9]{64}$/', $secret)
            ? ['id' => $id, 'secret' => $secret]
            : null;
    }

    private function browserPlatform(?string $userAgent): string
    {
        $agent = strtolower((string) $userAgent);

        return match (true) {
            str_contains($agent, 'android') => 'Android',
            str_contains($agent, 'iphone'), str_contains($agent, 'ipad') => 'iOS',
            str_contains($agent, 'windows') => 'Windows',
            str_contains($agent, 'macintosh') => 'macOS',
            str_contains($agent, 'linux') => 'Linux',
            default => 'Web',
        };
    }
}
