<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\LoggedException;

final readonly class ApplicationFrames
{
    /**
     * @param list<string> $vendorPaths
     */
    public function __construct(
        private array $vendorPaths = ['vendor/'],
    ) {
    }

    /**
     * If no frame belongs to the application, all do. A trace inside vendor code then still shows.
     *
     * @param list<string|null> $files
     *
     * @return list<bool>
     */
    public function mark(array $files): array
    {
        $isApplication = array_map(fn (?string $file): bool => $file !== null && !$this->isVendor($file), $files);

        return in_array(true, $isApplication, true) ? $isApplication : array_fill(0, count($files), true);
    }

    private function isVendor(string $file): bool
    {
        $file = str_replace('\\', '/', $file);
        foreach ($this->vendorPaths as $vendorPath) {
            $vendorPath = str_replace('\\', '/', $vendorPath);
            if (str_starts_with($file, $vendorPath) || str_contains($file, '/' . $vendorPath)) {
                return true;
            }
        }

        return false;
    }
}
