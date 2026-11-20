<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\ApiException;
use App\Repositories\CampaignRepository;

final class CampaignService
{
    public function __construct(
        private readonly CampaignRepository $campaignRepo,
        private readonly ?UserLimitsService $userLimitsService = null,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function listForUser(
        string $userId,
        int $page,
        int $perPage,
        ?string $createdAfter = null,
        ?string $createdBefore = null,
    ): array {
        $offset = ($page - 1) * $perPage;
        $result = $this->campaignRepo->listByUser($userId, $perPage, $offset, $createdAfter, $createdBefore);

        $items = array_map(fn (array $row): array => $this->serialize($row), $result['items']);
        return [
            'items' => $items,
            'meta' => [
                'total' => $result['total'],
                'count' => count($items),
                'page' => $page,
                'perPage' => $perPage,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function getForUser(string $campaignId, string $userId): array
    {
        if (!Uuid::isValid($campaignId)) {
            throw ApiException::notFound('CAMPAIGN_NOT_FOUND', 'Кампания не найдена');
        }
        $row = $this->campaignRepo->findById($campaignId, $userId);
        if ($row === null) {
            throw ApiException::notFound('CAMPAIGN_NOT_FOUND', 'Кампания не найдена');
        }
        return $this->serialize($row);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createForUser(string $userId, array $input): array
    {
        $this->userLimitsService?->assertCanCreateCampaign($userId);

        $name = trim((string) ($input['name'] ?? $input['title'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            throw ApiException::badRequest('VALIDATION_ERROR', 'Укажите название кампании (name, до 255 символов)');
        }

        $description = isset($input['description']) && $input['description'] !== null ? trim((string) $input['description']) : null;
        if ($description === '') {
            $description = null;
        }

        $tags = UrlTools::normalizeTags($input['tags'] ?? []);
        $utm = UrlTools::cleanUtm($input['utm'] ?? null);

        $startsAt = $this->parseOptionalDate($input['startsAt'] ?? null, 'startsAt');
        $endsAt = $this->parseOptionalDate($input['endsAt'] ?? null, 'endsAt');

        $id = Uuid::v4();
        $nowMysql = DateHelper::toMysqlUtc(DateHelper::nowUtc());

        $this->campaignRepo->create([
            'id' => $id,
            'user_id' => $userId,
            'name' => $name,
            'description' => $description,
            'tags_json' => $tags !== [] ? json_encode($tags, JSON_UNESCAPED_UNICODE) : null,
            'utm_json' => $utm !== [] ? json_encode($utm, JSON_UNESCAPED_UNICODE) : null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'created_at' => $nowMysql,
            'updated_at' => $nowMysql,
        ]);

        return $this->getForUser($id, $userId);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateForUser(string $campaignId, string $userId, array $input): array
    {
        if (!Uuid::isValid($campaignId) || !$this->campaignRepo->existsForUser($campaignId, $userId)) {
            throw ApiException::notFound('CAMPAIGN_NOT_FOUND', 'Кампания не найдена');
        }

        $fields = [];

        if (array_key_exists('name', $input) || array_key_exists('title', $input)) {
            $rawName = $input['name'] ?? $input['title'] ?? '';
            $name = trim((string) $rawName);
            if ($name === '' || mb_strlen($name) > 255) {
                throw ApiException::badRequest('VALIDATION_ERROR', 'Название кампании должно быть от 1 до 255 символов');
            }
            $fields['name'] = $name;
        }

        if (array_key_exists('description', $input)) {
            $desc = $input['description'] !== null ? trim((string) $input['description']) : null;
            $fields['description'] = ($desc === '') ? null : $desc;
        }

        if (array_key_exists('tags', $input)) {
            $tags = UrlTools::normalizeTags($input['tags']);
            $fields['tags_json'] = $tags !== [] ? json_encode($tags, JSON_UNESCAPED_UNICODE) : null;
        }

        if (array_key_exists('utm', $input)) {
            $utm = UrlTools::cleanUtm($input['utm']);
            $fields['utm_json'] = $utm !== [] ? json_encode($utm, JSON_UNESCAPED_UNICODE) : null;
        }

        if (array_key_exists('startsAt', $input)) {
            $fields['starts_at'] = $this->parseOptionalDate($input['startsAt'], 'startsAt');
        }

        if (array_key_exists('endsAt', $input)) {
            $fields['ends_at'] = $this->parseOptionalDate($input['endsAt'], 'endsAt');
        }

        $nowMysql = DateHelper::toMysqlUtc(DateHelper::nowUtc());
        $this->campaignRepo->update($campaignId, $userId, $fields, $nowMysql);

        return $this->getForUser($campaignId, $userId);
    }

    public function deleteForUser(string $campaignId, string $userId): void
    {
        if (!Uuid::isValid($campaignId)) {
            throw ApiException::notFound('CAMPAIGN_NOT_FOUND', 'Кампания не найдена');
        }
        $nowMysql = DateHelper::toMysqlUtc(DateHelper::nowUtc());
        $deleted = $this->campaignRepo->softDeleteAndDetachShortcuts($campaignId, $userId, $nowMysql);
        if (!$deleted) {
            throw ApiException::notFound('CAMPAIGN_NOT_FOUND', 'Кампания не найдена');
        }
    }

    public function countActiveShortcuts(string $campaignId, string $userId): int
    {
        return $this->campaignRepo->countActiveShortcuts($campaignId, $userId);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function serialize(array $row): array
    {
        $tags = is_string($row['tags_json'] ?? null) ? json_decode($row['tags_json'], true) : [];
        $utm = is_string($row['utm_json'] ?? null) ? json_decode($row['utm_json'], true) : null;

        return [
            'campaignId' => (string) $row['id'],
            'name' => (string) $row['name'],
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'tags' => is_array($tags) ? array_values($tags) : [],
            'utm' => is_array($utm) && $utm !== [] ? $utm : null,
            'startsAt' => DateHelper::mysqlToIso($row['starts_at'] ?? null),
            'endsAt' => DateHelper::mysqlToIso($row['ends_at'] ?? null),
            'linksCount' => (int) ($row['links_count'] ?? 0),
            'totalClicks' => (int) ($row['total_clicks'] ?? 0),
            'createdAt' => DateHelper::mysqlToIso($row['created_at'] ?? null),
            'modifiedAt' => DateHelper::mysqlToIso($row['updated_at'] ?? null),
        ];
    }

    private function parseOptionalDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $parsed = DateHelper::parseUtc((string) $value);
        if ($parsed === null) {
            throw ApiException::badRequest('VALIDATION_ERROR', "Некорректный формат даты {$field}");
        }
        return DateHelper::toMysqlUtc($parsed);
    }
}
