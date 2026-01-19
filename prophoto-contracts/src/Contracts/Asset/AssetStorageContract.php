<?php

namespace ProPhoto\Contracts\Contracts\Asset;

use ProPhoto\Contracts\DTOs\AssetId;
use ProPhoto\Contracts\Enums\DerivativeType;

interface AssetStorageContract
{
    /**
     * Store an asset file.
     */
    public function store(string $filePath, AssetId $assetId, DerivativeType $type): string;

    /**
     * Retrieve an asset file path.
     */
    public function retrieve(AssetId $assetId, DerivativeType $type): ?string;

    /**
     * Delete an asset and all its derivatives.
     */
    public function delete(AssetId $assetId): void;

    /**
     * Check if an asset exists.
     */
    public function exists(AssetId $assetId, DerivativeType $type): bool;
}
