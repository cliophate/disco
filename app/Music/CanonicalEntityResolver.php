<?php

namespace App\Music;

use App\Models\CatalogEntity;

class CanonicalEntityResolver
{
    public function resolve(string $id, ?string $kind = null): ?CatalogEntity
    {
        $visited = [];
        for ($depth = 0; $depth < 10; $depth++) {
            if (isset($visited[$id])) {
                return null;
            }
            $visited[$id] = true;
            $entity = CatalogEntity::query()->find($id);
            if ($entity === null) {
                return null;
            }
            if ($entity->status === 'active') {
                return $kind === null || $entity->kind === $kind ? $entity : null;
            }
            if ($entity->status !== 'redirected' || $entity->redirect_entity_id === null) {
                return null;
            }
            $id = $entity->redirect_entity_id;
        }

        return null;
    }
}
