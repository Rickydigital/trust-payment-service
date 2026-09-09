<?php

namespace App\Console\Commands;

use App\Services\Media\ImageNormalizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class NormalizeStoredImages extends Command
{
    protected $signature = 'media:normalize-images
        {--disk=public : Filesystem disk to scan}
        {--path= : Limit processing to a directory prefix}
        {--profile=auto : Image profile or auto}
        {--memory=512M : Temporary PHP memory limit for legacy source images}
        {--max-pixels=60000000 : Maximum legacy image pixels accepted by this command}
        {--force : Replace existing variants and normalized originals}
        {--dry-run : List the amount of work without changing files}';

    protected $description = 'Normalize stored images and generate thumbnail, card, and detail variants';

    public function handle(ImageNormalizationService $images): int
    {
        $memory = trim((string) $this->option('memory'));
        if ($memory !== '') {
            ini_set('memory_limit', $memory);
        }
        config(['image_processing.max_pixels' => max(1000000, (int) $this->option('max-pixels'))]);

        $disk = (string) $this->option('disk');
        $prefix = trim((string) $this->option('path'), '/');
        $profile = (string) $this->option('profile');
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');
        $files = Storage::disk($disk)->allFiles($prefix);
        $candidates = array_values(array_filter($files, fn (string $path) => $images->isProcessable($path)));

        $alreadyNormalized = 0;
        if (! $force) {
            $pending = [];
            foreach ($candidates as $path) {
                if ($images->hasAllVariants($path, $disk)) {
                    $alreadyNormalized++;
                } else {
                    $pending[] = $path;
                }
            }
            $candidates = $pending;
        }

        $this->info(sprintf(
            'Found %d pending image(s) on %s%s; %d already normalized.',
            count($candidates),
            $disk,
            $prefix ? ":{$prefix}" : '',
            $alreadyNormalized
        ));
        if ($dryRun) {
            return self::SUCCESS;
        }

        if ($candidates === []) {
            return self::SUCCESS;
        }

        $processed = 0;
        $failed = 0;
        $bar = $this->output->createProgressBar(count($candidates));
        $bar->start();

        foreach ($candidates as $path) {
            try {
                $images->normalizeExisting($path, $profile, $disk, $force);
                $processed++;
            } catch (Throwable $exception) {
                $failed++;
                $this->newLine();
                $this->warn("Skipped {$path}: {$exception->getMessage()}");
            } finally {
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Normalized {$processed} image(s); {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
