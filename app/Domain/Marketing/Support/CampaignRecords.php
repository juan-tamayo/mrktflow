<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CampaignRecords
{
    /** @return array<string, mixed> */
    public function campaign(string $id): array
    {
        $row = DB::table('marketing_campaigns')->where('id', $id)->first();
        if ($row === null) {
            throw new NotFoundHttpException;
        }

        return $this->row($row);
    }

    /** @return array<string, mixed> */
    public function version(?string $id): ?array
    {
        if ($id === null || $id === '') {
            return null;
        }
        $row = DB::table('campaign_versions')->where('id', $id)->first();
        if ($row === null) {
            return null;
        }

        return $this->versionRow($row);
    }

    /**
     * @param  array<string, mixed>  $campaign
     * @return array<string, mixed>
     */
    public function workingVersion(array $campaign): array
    {
        $draft = $this->version($this->string($campaign, 'draft_version_id'));
        if ($draft === null) {
            throw new NotFoundHttpException;
        }

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $campaign
     * @return array<string, mixed>
     */
    public function scheduledVersion(array $campaign): array
    {
        $activated = $this->version($this->string($campaign, 'activated_version_id'));

        return $activated ?? $this->workingVersion($campaign);
    }

    /** @return array<string, mixed> */
    public function row(object $row): array
    {
        return [
            'id' => $this->string($row, 'id'),
            'name' => $this->string($row, 'name'),
            'status' => $this->string($row, 'status'),
            'revision' => $this->int($row, 'revision'),
            'draft_version_id' => $this->nullable($row, 'draft_version_id'),
            'activated_version_id' => $this->nullable($row, 'activated_version_id'),
            'activated_at' => $this->time($row, 'activated_at'),
            'paused_at' => $this->time($row, 'paused_at'),
        ];
    }

    /** @return array<string, mixed> */
    public function versionRow(object $row): array
    {
        return [
            'id' => $this->string($row, 'id'),
            'campaign_id' => $this->string($row, 'campaign_id'),
            'version' => $this->int($row, 'version'),
            'subject' => $this->string($row, 'subject'),
            'body_text' => $this->string($row, 'body_text'),
            'purpose' => $this->string($row, 'purpose'),
            'audience' => $this->json($row, 'audience_filters'),
            'schedule' => $this->json($row, 'schedule'),
            'caps' => $this->json($row, 'caps'),
            'offer_reference' => $this->nullable($row, 'offer_reference'),
        ];
    }

    /** @return array<string, mixed> */
    public function json(object $row, string $key): array
    {
        $value = get_object_vars($row)[$key] ?? null;
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Campaign JSON is invalid.');
        }

        return $decoded;
    }

    public function instant(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    public function iso(?DateTimeImmutable $time): ?string
    {
        return $time?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public function string(object|array $row, string $key): string
    {
        $value = is_array($row) ? ($row[$key] ?? null) : (get_object_vars($row)[$key] ?? null);

        return is_scalar($value) ? (string) $value : '';
    }

    public function int(object|array $row, string $key): int
    {
        $value = is_array($row) ? ($row[$key] ?? null) : (get_object_vars($row)[$key] ?? null);

        return is_numeric($value) ? (int) $value : 0;
    }

    public function nullable(object $row, string $key): ?string
    {
        $value = $this->string($row, $key);

        return $value === '' ? null : $value;
    }

    public function time(object $row, string $key): ?string
    {
        return $this->iso($this->instant($this->nullable($row, $key)));
    }
}
