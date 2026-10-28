<?php
declare(strict_types=1);
namespace MaaleArna\MASES\Core\Domains\People\Contracts;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
interface PersonProfileRepositoryContract {
    public function paginateVisible(int $tenantId, string $search, int $perPage): LengthAwarePaginator;
    public function findVisible(int $tenantId, string $publicId): ?Person;
}
