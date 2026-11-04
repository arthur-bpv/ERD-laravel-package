<?php

namespace Makermind\Erd4dev\Services;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final class BlueprintDraftWriter
{
    public function write(int $diagramId, string $yaml): string
    {
        $directory = base_path('blueprints');
        (new Filesystem)->ensureDirectoryExists($directory);
        // Exclusive creation also protects two clicks in the same microsecond.
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $filename = 'draft-'.$diagramId.'-'.now()->format('Ymd-Hisu').($attempt ? '-'.$attempt : '').'.yaml';
            $handle = @fopen($directory.DIRECTORY_SEPARATOR.$filename, 'x');
            if ($handle === false) {
                if (file_exists($directory.DIRECTORY_SEPARATOR.$filename)) {
                    continue;
                }
                throw new RuntimeException('Could not create the draft. Check write permissions for the blueprints directory.');
            }
            try {
                $offset = 0;
                while ($offset < strlen($yaml)) {
                    $written = fwrite($handle, substr($yaml, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Could not write the complete draft.');
                    }
                    $offset += $written;
                }
            } catch (\Throwable $exception) {
                fclose($handle);
                unlink($directory.DIRECTORY_SEPARATOR.$filename);
                throw $exception;
            }
            fclose($handle);

            return 'blueprints/'.$filename;
        }

        throw new RuntimeException('Could not reserve a filename for the new draft. Try again.');
    }
}
