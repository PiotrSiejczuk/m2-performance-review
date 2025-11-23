<?php

namespace M2Performance\Model;

class Recommendation
{
    const PRIORITY_HIGH = 3;
    const PRIORITY_MEDIUM = 2;
    const PRIORITY_LOW = 1;

    private string $area;
    private string $title;
    private int $priority;
    private string $details;
    private ?string $explanation;
    private array $files = [];
    private array $metadata = [];

    public function __construct(
        string $area,
        string $title,
        int $priority,
        string $details,
        ?string $explanation = null,
        array $files = [],
        array $metadata = []
    ) {
        $this->area = $area;
        $this->title = $title;
        $this->priority = $priority;
        $this->details = $details;
        $this->explanation = $explanation;
        $this->files = $files;
        $this->metadata = $metadata;
    }

    public function getArea(): string
    {
        return $this->area;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getDetails(): string
    {
        return $this->details;
    }

    public function getExplanation(): ?string
    {
        return $this->explanation;
    }

    public function getFiles(): array
    {
        return $this->files;
    }

    public function hasFiles(): bool
    {
        return !empty($this->files);
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function hasMetadata(): bool
    {
        return !empty($this->metadata);
    }
}
