<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\Support\CampaignRecords;
use Illuminate\Support\Facades\DB;

final class ReadCampaigns
{
    /**
     * @return list<array{id: string, name: string, status: string, revision: int}>
     */
    public function staffCampaigns(): array
    {
        $rows = [];
        $records = app(CampaignRecords::class);
        foreach (DB::table('marketing_campaigns')->orderByDesc('updated_at')->orderBy('id')->get() as $campaign) {
            $rows[] = [
                'id' => $records->string($campaign, 'id'),
                'name' => $records->string($campaign, 'name'),
                'status' => $records->string($campaign, 'status'),
                'revision' => $records->int($campaign, 'revision'),
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function staffCampaign(string $id): array
    {
        $records = app(CampaignRecords::class);
        $campaign = $records->campaign($id);
        $version = $records->workingVersion($campaign);

        return [
            'id' => $campaign['id'],
            'name' => $campaign['name'],
            'status' => $campaign['status'],
            'version' => $campaign['revision'],
            'subject' => $version['subject'],
            'body_text' => $version['body_text'],
            'purpose' => $version['purpose'],
            'audience' => $version['audience'],
            'schedule' => $version['schedule'],
            'caps' => $version['caps'],
            'offer_reference' => $version['offer_reference'],
            'preview' => null,
        ];
    }

    /** @return list<array{id: string, title: string, path: string}> */
    public function categories(): array
    {
        $records = app(CampaignRecords::class);
        $rows = [];
        foreach (DB::table('categories')->orderBy('path')->limit(200)->get() as $category) {
            $rows[] = [
                'id' => $records->string($category, 'id'),
                'title' => $records->string($category, 'title'),
                'path' => $records->string($category, 'path'),
            ];
        }

        return $rows;
    }
}
