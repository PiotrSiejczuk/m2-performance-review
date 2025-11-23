<?php

namespace M2Performance\Helper;

class ImprovedTableFormatter
{
    private int $terminalWidth;
    private array $columnWidths;
    private array $headers;
    private array $rows;

    // Column configuration with max widths and priorities
    private array $columnConfig = [
        'Area' => ['max' => 15, 'min' => 10, 'priority' => 1],
        'Priority' => ['max' => 20, 'min' => 10, 'priority' => 1],
        'Recommendation' => ['max' => 40, 'min' => 20, 'priority' => 2],
        'Details' => ['max' => 30, 'min' => 20, 'priority' => 3],
        'Explanation' => ['max' => 50, 'min' => 40, 'priority' => 4],
    ];

    public function __construct()
    {
        $this->terminalWidth = $this->getTerminalWidth();
        $this->headers = [];
        $this->rows = [];
    }

    public function setHeaders(array $headers): self
    {
        $this->headers = $headers;
        $this->calculateColumnWidths();
        return $this;
    }

    public function addRow(array $row): self
    {
        $this->rows[] = $row;
        return $this;
    }

    public function render(): string
    {
        if (empty($this->headers) || empty($this->rows)) {
            return '';
        }

        $output = '';

        // Top border
        $output .= $this->renderBorder('top') . "\n";

        // Headers
        $output .= $this->renderRow($this->headers, true) . "\n";

        // Header separator
        $output .= $this->renderBorder('middle') . "\n";

        // Data rows
        foreach ($this->rows as $row) {
            $wrappedRows = $this->wrapRow($row);
            foreach ($wrappedRows as $wrappedRow) {
                $output .= $this->renderRow($wrappedRow) . "\n";
            }
        }

        // Bottom border
        $output .= $this->renderBorder('bottom');

        return $output;
    }

    private function getTerminalWidth(): int
    {
        // Try to get terminal width from environment
        $width = getenv('COLUMNS');
        if ($width && is_numeric($width)) {
            return (int)$width;
        }

        // Try using tput command
        $width = @exec('tput cols 2>/dev/null');
        if ($width && is_numeric($width)) {
            return (int)$width;
        }

        // Try using stty command
        $stty = @exec('stty size 2>/dev/null');
        if ($stty && preg_match('/\d+\s+(\d+)/', $stty, $matches)) {
            return (int)$matches[1];
        }

        // Default fallback
        return 120;
    }

    private function calculateColumnWidths(): void
    {
        $headerCount = count($this->headers);
        $borderChars = 3 + ($headerCount - 1) * 3 + 3; // ║ + │ + ║
        $availableWidth = $this->terminalWidth - $borderChars;

        // Initialize with minimum widths
        $this->columnWidths = [];
        $totalMinWidth = 0;

        foreach ($this->headers as $index => $header) {
            $headerName = $header;
            $config = $this->columnConfig[$headerName] ?? ['max' => 30, 'min' => 15, 'priority' => 5];
            $this->columnWidths[$index] = $config['min'];
            $totalMinWidth += $config['min'];
        }

        // Distribute remaining width based on priority and max limits
        $remainingWidth = $availableWidth - $totalMinWidth;

        if ($remainingWidth > 0) {
            // Sort columns by priority (lower number = higher priority)
            $priorities = [];
            foreach ($this->headers as $index => $header) {
                $config = $this->columnConfig[$header] ?? ['priority' => 5];
                $priorities[$index] = $config['priority'];
            }
            asort($priorities);

            // Distribute width to high-priority columns first
            foreach ($priorities as $index => $priority) {
                if ($remainingWidth <= 0) break;

                $header = $this->headers[$index];
                $config = $this->columnConfig[$header] ?? ['max' => 30];
                $maxWidth = $config['max'];
                $currentWidth = $this->columnWidths[$index];

                $canGrow = $maxWidth - $currentWidth;
                $willGrow = min($canGrow, $remainingWidth);

                $this->columnWidths[$index] += $willGrow;
                $remainingWidth -= $willGrow;
            }
        }
    }

    private function wrapRow(array $row): array
    {
        $wrappedCells = [];
        $maxLines = 1;

        // Wrap each cell and find max line count
        foreach ($row as $index => $cell) {
            $width = $this->columnWidths[$index] - 2; // Account for padding
            $wrappedCells[$index] = $this->wrapText($cell, $width);
            $maxLines = max($maxLines, count($wrappedCells[$index]));
        }

        // Create rows with proper line distribution
        $wrappedRows = [];
        for ($line = 0; $line < $maxLines; $line++) {
            $wrappedRow = [];
            foreach ($row as $index => $cell) {
                $wrappedRow[$index] = $wrappedCells[$index][$line] ?? '';
            }
            $wrappedRows[] = $wrappedRow;
        }

        return $wrappedRows;
    }

    private function wrapText(string $text, int $width): array
    {
        if ($width < 5) {
            return [substr($text, 0, $width)];
        }

        // Handle empty or short text
        if (empty($text) || strlen($text) <= $width) {
            return [$text];
        }

        // Use wordwrap for better text wrapping
        $wrapped = wordwrap($text, $width, "\n", true);
        return explode("\n", $wrapped);
    }

    private function renderRow(array $row, bool $isHeader = false): string
    {
        $output = '║';

        foreach ($row as $index => $cell) {
            $width = $this->columnWidths[$index];
            $paddedCell = $this->padCell($cell, $width, $isHeader);
            $output .= ' ' . $paddedCell . ' ';

            if ($index < count($row) - 1) {
                $output .= '│';
            }
        }

        $output .= '║';
        return $output;
    }

    private function padCell(string $cell, int $width, bool $isHeader = false): string
    {
        $cellWidth = $width - 2; // Account for padding spaces

        if (strlen($cell) >= $cellWidth) {
            return substr($cell, 0, $cellWidth);
        }

        // Center headers, left-align content
        if ($isHeader) {
            return str_pad($cell, $cellWidth, ' ', STR_PAD_BOTH);
        } else {
            return str_pad($cell, $cellWidth, ' ', STR_PAD_RIGHT);
        }
    }

    private function renderBorder(string $type): string
    {
        $chars = [
            'top' => ['╔', '═', '╤', '╗'],
            'middle' => ['╠', '═', '╪', '╣'],
            'bottom' => ['╚', '═', '╧', '╝'],
        ];

        [$start, $line, $separator, $end] = $chars[$type];

        $output = $start;

        foreach ($this->columnWidths as $index => $width) {
            $output .= str_repeat($line, $width);

            if ($index < count($this->columnWidths) - 1) {
                $output .= $separator;
            }
        }

        $output .= $end;
        return $output;
    }

    // Helper method for converting priority to emoji
    public static function formatPriority(string $priority): string
    {
        $priorities = [
            'high' => '🔴 High',
            'medium' => '🟡 Medium',
            'low' => '🟢 Low',
        ];

        $lower = strtolower($priority);
        return $priorities[$lower] ?? $priority;
    }

    // Helper method for truncating long text with ellipsis
    public static function truncateText(string $text, int $maxLength): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }

        return substr($text, 0, $maxLength - 3) . '...';
    }
}

// Usage example for the explain mode
class ExplainFormatter
{
    private ImprovedTableFormatter $table;

    public function __construct()
    {
        $this->table = new ImprovedTableFormatter();
    }

    public function formatRecommendations(array $recommendations): string
    {
        $this->table->setHeaders(['Area', 'Priority', 'Recommendation', 'Details', 'Explanation']);

        foreach ($recommendations as $recommendation) {
            $this->table->addRow([
                $recommendation['area'] ?? '',
                ImprovedTableFormatter::formatPriority($recommendation['priority'] ?? ''),
                $recommendation['title'] ?? '',
                $recommendation['description'] ?? '',
                $recommendation['explanation'] ?? ''
            ]);
        }

        return $this->table->render();
    }

    public function formatSimpleRecommendations(array $recommendations): string
    {
        $table = new ImprovedTableFormatter();
        $table->setHeaders(['Area', 'Priority', 'Recommendation', 'Details']);

        foreach ($recommendations as $recommendation) {
            $table->addRow([
                $recommendation['area'] ?? '',
                ImprovedTableFormatter::formatPriority($recommendation['priority'] ?? ''),
                $recommendation['title'] ?? '',
                $recommendation['description'] ?? ''
            ]);
        }

        return $table->render();
    }
}
