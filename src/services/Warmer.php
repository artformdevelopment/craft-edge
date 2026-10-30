<?php

declare(strict_types=1);

namespace artformdev\edge\services;

use artformdev\edge\db\Table;
use artformdev\edge\jobs\PurgeJob;
use artformdev\edge\jobs\WarmJob;
use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Queue;
use RuntimeException;

/** Durable warming work, independent of cache eviction and shared across workers. */
class Warmer extends Component
{
    public const PRIORITY = 2048;
    public const TTR = 300;
    public const LEASE = 120;

    public static function batchSize(int $concurrency): int
    {
        return min(10, 2 * max(1, $concurrency));
    }

    public function enqueue(array $siteUris, int $delay = 0): void
    {
        $unique = [];
        foreach ($siteUris as $uri) {
            $unique[hash('sha256', (int)$uri['siteId'] . ':' . $uri['uri'])] = $uri;
        }
        foreach (array_chunk($unique, 10, true) as $chunk) {
            $this->locked(function() use ($chunk, $delay): void {
                $ids = [];
                foreach ($chunk as $key => $uri) {
                    $row = (new Query())->from(Table::WARM_REQUESTS)->where(['urlKey' => $key])->one();
                    $db = Craft::$app->getDb();
                    if ($row === null) {
                        $db->createCommand()->insert(Table::WARM_REQUESTS, [
                        'siteId' => (int)$uri['siteId'], 'uri' => $uri['uri'], 'urlKey' => $key, 'generation' => 1,
                    ])->execute();
                        $ids[] = (int)$db->getLastInsertID();
                        continue;
                    }
                    $db->createCommand()->update(Table::WARM_REQUESTS, [
                    'generation' => (int)$row['generation'] + 1, 'lastError' => null,
                ], ['id' => $row['id']])->execute();
                    if ($row['lastError'] !== null || ($row['leaseUntil'] !== null && (int)$row['leaseUntil'] <= time())) {
                        $ids[] = (int)$row['id'];
                    }
                }
                $this->dispatch($ids, $delay);
            });
        }
    }

    public function dispatch(array $ids, int $delay = 0): void
    {
        foreach (array_chunk($ids, 10) as $chunk) {
            if (Queue::push(new WarmJob(requestIds: $chunk), self::PRIORITY, $delay, self::TTR) === null) {
                throw new RuntimeException('Unable to queue Edge warming work.');
            }
        }
    }

    public function claim(int $id): ?array
    {
        $row = $this->find($id);
        if ($row === null) {
            return null;
        }
        return $this->locked(function() use ($id): ?array {
            $row = $this->find($id);
            if ($row === null || ($row['leaseUntil'] !== null && (int)$row['leaseUntil'] > time())) {
                return null;
            }
            $row['claimToken'] = bin2hex(random_bytes(16));
            Craft::$app->getDb()->createCommand()->update(Table::WARM_REQUESTS, [
                'claimToken' => $row['claimToken'], 'leaseUntil' => time() + self::LEASE,
            ], ['id' => $id])->execute();
            return $row;
        });
    }

    public function complete(array $claim, ?string $error): void
    {
        $this->locked(function() use ($claim, $error): void {
            $row = $this->find((int)$claim['id']);
            if ($row === null || $row['claimToken'] !== $claim['claimToken']) {
                return;
            }
            $db = Craft::$app->getDb();
            if ((int)$row['generation'] !== (int)$claim['generation']) {
                $db->createCommand()->update(Table::WARM_REQUESTS, [
                    'claimToken' => null, 'leaseUntil' => null, 'lastError' => null,
                ], ['id' => $row['id']])->execute();
                if (Queue::push(new PurgeJob(siteUris: [['siteId' => (int)$row['siteId'], 'uri' => $row['uri']]]), 100) === null) {
                    throw new RuntimeException('Unable to queue Edge follow-up purge.');
                }
                $this->dispatch([(int)$row['id']], 5);
            } elseif ($error === null) {
                $db->createCommand()->delete(Table::WARM_REQUESTS, ['id' => $row['id']])->execute();
            } else {
                $db->createCommand()->update(Table::WARM_REQUESTS, [
                    'claimToken' => null, 'leaseUntil' => null, 'lastError' => $error,
                ], ['id' => $row['id']])->execute();
            }
        });
    }

    private function find(int $id): ?array
    {
        $row = (new Query())->from(Table::WARM_REQUESTS)->where(['id' => $id])->one();
        return $row === null ? null : $row;
    }

    private function locked(callable $callback): mixed
    {
        $mutex = Craft::$app->getMutex();
        $name = 'edge:warm:work-list';
        if (!$mutex->acquire($name, 10)) {
            throw new RuntimeException('Unable to lock Edge warming work.');
        }
        try {
            return Craft::$app->getDb()->transaction($callback);
        } finally {
            $mutex->release($name);
        }
    }
}
