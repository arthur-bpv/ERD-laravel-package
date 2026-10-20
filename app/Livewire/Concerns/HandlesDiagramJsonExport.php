<?php

namespace App\Livewire\Concerns;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * JSON modal shared by the ER and relational boards.
 *
 * Both boards use <x-json-modal>, `showJson`, and `downloadJson`. Each board
 * supplies its preview content and filename; this trait handles the modal.
 *
 * Only Livewire components use this trait.
 */
trait HandlesDiagramJsonExport
{
    /** The Blade view reads this modal state. */
    public bool $showJson = false;

    /**
     * Formatted JSON of the model.
     *
     * Each board implements its own ER or relational format.
     */
    abstract public function getJsonPreviewProperty(): string;

    /**
     * Filename for the download.
     *
     * This is a method because PHP cannot redeclare a trait property with a
     * different default value in each board.
     */
    abstract protected function jsonFileName(): string;

    /** Toggle the diagram JSON modal. */
    public function toggleJson(): void
    {
        $this->showJson = ! $this->showJson;
    }

    /**
     * Download the JSON shown in the modal.
     */
    public function downloadJson(): StreamedResponse
    {
        $json = $this->jsonPreview;

        return response()->streamDownload(
            static function () use ($json): void {
                echo $json;
            },
            $this->jsonFileName(),
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }
}
