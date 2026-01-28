<?php

namespace App\Services;

use App\Models\Contract;

class ContractService
{
    /**
     * Prepare data for PDF generation, including Base64 images.
     */
    public function preparePdfData(Contract $contract): array
    {
        $company = \App\Models\Company::current();

        return [
            'contract' => $contract,
            'company' => $company,
            'currentDate' => now()->format('d/m/Y'),
            'currentTime' => now()->format('H:i'),
            'logoSrc' => $this->convertStorageFileToBase64($company->logo_path),
        ];
    }

    /**
     * Convert a stored image file to a Base64 string.
     */
    private function convertStorageFileToBase64(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        $fullPath = storage_path('app/public/' . $path);

        if (!file_exists($fullPath)) {
            return null;
        }

        $extension = pathinfo($fullPath, PATHINFO_EXTENSION);
        $data = file_get_contents($fullPath);

        $mime = match (strtolower($extension)) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };

        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }
}
