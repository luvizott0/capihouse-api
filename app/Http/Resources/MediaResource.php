<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'path' => $this->path,
            'type' => $this->type,
            'collection_name' => $this->collection_name,
            'url' => ($this->resource && method_exists($this->resource, 'getUrl')) ? $this->resource->getUrl() : ($this->path ?? ''),
        ];
    }
}
