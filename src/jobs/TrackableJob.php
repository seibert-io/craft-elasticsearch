<?php

/**
 * @author David Seibert<david@seibert.io>
 */

namespace seibertio\elasticsearch\jobs;

use Craft;
use craft\base\Component;
use craft\helpers\ElementHelper;
use yii\caching\CacheInterface;

abstract class TrackableJob extends Component
{
    private int $expiresIn = 60 * 60 * 2; // 2hrs

    public string $cacheName = 'cache';

    abstract public function getCacheId(): string;

    public function getBaseCacheKey(): string
    {
        return ElementHelper::createSlug(get_class($this)) . '-';
    }

    public function getCacheKey(): string
    {
        return $this->getBaseCacheKey() . $this->getCacheId();
    }

    public function isQueued(): bool
    {
        $jobInfo = $this->getJobInfo();

        return $jobInfo !== false;
    }

    public function markQueued($queueId): void
    {
        $this->set(['queueId' => $queueId, 'progress' => 0]);
    }

    public function markCompleted(): void
    {
        $this->delete();
    }

    public function updateProgress(float $progress): void
    {
        $jobInfo = $this->getJobInfo();
        $jobInfo['progress'] = $progress;

        $this->set($jobInfo);
    }

    /**
     * @return float|false
     */
    public function getProgress()
    {
        $jobInfo = $this->getJobInfo();

        if ($jobInfo) return $jobInfo['progress'];
    }

    private function getCache(): CacheInterface
    {
        return Craft::$app->{$this->cacheName};
    }

    private function getExpiresIn(): int
    {
        return $this->expiresIn;
    }

    private function getJobInfo()
    {
        $cache = self::getCache();
        return $cache->get($this->getCacheKey());
    }

    private function set($jobInfo): void
    {
        $cache = self::getCache();
        $cache->set($this->getCacheKey(), $jobInfo, $this->getExpiresIn());
    }

    private function delete()
    {
        $cache = self::getCache();
        return $cache->delete($this->getCacheKey());
    }
}
