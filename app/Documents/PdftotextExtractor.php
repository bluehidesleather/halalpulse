<?php

declare(strict_types=1);

namespace HalalPulse\Documents;

use Throwable;

final class PdftotextExtractor implements PdfTextExtractor
{
    public function __construct(
        private readonly string $binary,
        private readonly string $temporaryDirectory,
        private readonly int $timeoutSeconds = 30,
        private readonly int $maxTextBytes = 5_242_880,
    ) {
    }

    public function available(): bool
    {
        return function_exists('proc_open') && is_file($this->binary) && is_executable($this->binary);
    }

    public function extract(string $absolutePdfPath): string
    {
        if (!$this->available()) {
            throw new ExtractionException('The configured pdftotext extractor is unavailable.');
        }

        if (!is_file($absolutePdfPath)) {
            throw new ExtractionException('The private PDF file is missing.');
        }

        if (!is_dir($this->temporaryDirectory)
            && !mkdir($this->temporaryDirectory, 0770, true)
            && !is_dir($this->temporaryDirectory)
        ) {
            throw new ExtractionException('Unable to create the extraction temporary directory.');
        }

        $outputPath = rtrim($this->temporaryDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'extract-' . bin2hex(random_bytes(8)) . '.txt';
        $pipes = [];
        $process = proc_open(
            [$this->binary, '-layout', '-enc', 'UTF-8', $absolutePdfPath, $outputPath],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (!is_resource($process)) {
            if (is_file($outputPath)) {
                unlink($outputPath);
            }
            throw new ExtractionException('Unable to start the PDF text extractor.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stderr = '';
        $startedAt = microtime(true);
        $exitCode = null;
        $loopException = null;

        try {
            do {
                $status = proc_get_status($process);
                stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                $stderr = mb_substr($stderr, 0, 8192);

                if (!$status['running']) {
                    $exitCode = (int) $status['exitcode'];
                    break;
                }

                if ((microtime(true) - $startedAt) > max(1, $this->timeoutSeconds)) {
                    proc_terminate($process, 9);
                    throw new ExtractionException('PDF text extraction exceeded its time limit.');
                }

                usleep(50_000);
            } while (true);
        } catch (Throwable $exception) {
            $loopException = $exception;
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closedExitCode = proc_close($process);
            $exitCode ??= $closedExitCode;
        }

        if ($loopException !== null) {
            if (is_file($outputPath)) {
                unlink($outputPath);
            }

            throw $loopException;
        }

        try {
            if ($exitCode !== 0 || !is_file($outputPath)) {
                throw new ExtractionException('PDF text extraction failed: ' . $this->safeError($stderr));
            }

            $size = filesize($outputPath);
            if (!is_int($size) || $size < 1 || $size > $this->maxTextBytes) {
                throw new ExtractionException('Extracted PDF text is empty or exceeds the configured limit.');
            }

            $text = file_get_contents($outputPath);
            if (!is_string($text)) {
                throw new ExtractionException('Unable to read extracted PDF text.');
            }

            $text = str_replace("\0", '', $text);
            if (!mb_check_encoding($text, 'UTF-8')) {
                $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            }

            $text = trim($text);
            if (mb_strlen($text) < 100) {
                throw new ExtractionException('Extracted PDF text is too short for automated review.');
            }

            return $text;
        } finally {
            if (is_file($outputPath)) {
                unlink($outputPath);
            }
        }
    }

    private function safeError(string $stderr): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $stderr) ?? '');

        return $message === '' ? 'no diagnostic provided' : mb_substr($message, 0, 300);
    }
}
