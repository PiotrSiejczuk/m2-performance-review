<?php

namespace M2Performance\Helper;

use M2Performance\Model\Recommendation;

class RecommendationCollector
{
    private array $recommendations = [];

    public function add(
        string $area,
        string $title,
        int $priority,
        string $details,
        ?string $explanation = null
    ): void {
        $this->recommendations[] = new Recommendation(
            $area,
            $title,
            $priority,
            $details,
            $explanation
        );
    }

    public function addWithFiles(
        string $area,
        string $title,
        int $priority,
        string $details,
        array $files,
        ?string $explanation = null,
        array $metadata = []
    ): void {
        $this->recommendations[] = new Recommendation(
            $area,
            $title,
            $priority,
            $details,
            $explanation,
            $files,
            $metadata
        );
    }

    public function getRecommendations(): array
    {
        return $this->recommendations;
    }

    public function getRecommendationsByArea(string $area): array
    {
        return array_filter(
            $this->recommendations,
            fn($r) => $r->getArea() === $area
        );
    }

    public function getRecommendationsByPriority(int $priority): array
    {
        return array_filter(
            $this->recommendations,
            fn($r) => $r->getPriority() === $priority
        );
    }

    public function clear(): void
    {
        $this->recommendations = [];
    }

    public function count(): int
    {
        return count($this->recommendations);
    }
}
