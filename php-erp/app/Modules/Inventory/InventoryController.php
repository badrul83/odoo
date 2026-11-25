<?php
declare(strict_types=1);

namespace Erp\Modules\Inventory;

use Erp\Core\Config;
use Erp\Core\Database;
use Erp\Core\Http\Request;
use Erp\Core\Http\Response;
use Erp\Core\Security\AccessDeniedException;
use Erp\Core\Security\AuthenticationRequiredException;
use Erp\Core\Security\AuthService;
use Erp\Core\Security\Authorization;
use Erp\Core\Security\Session;
use Erp\Core\Security\UserContext;
use InvalidArgumentException;
use PDOException;
use Throwable;

final class InventoryController
{
    public function __construct(private readonly Config $config)
    {
    }

    public function options(Request $request, array $params): Response
    {
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'inventory.read');
            $companyId = $this->companyId($request->query()['company_id'] ?? null);
            return Response::json(['ok' => true, 'options' => $repository->options($user, $authorization, $companyId)]);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Inventory options failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Inventory setup options could not be loaded.'], 500);
        }
    }

    public function productOptions(Request $request, array $params): Response
    {
        try {
            [$user, $authorization, $repository] = $this->context();
            if (!$authorization->allows('products.create')) {
                throw new AccessDeniedException('You do not have Products create access.');
            }
            $companyId = $this->companyId($request->query()['company_id'] ?? null);
            return Response::json(['ok' => true, 'options' => $repository->productOptions($user, $authorization, $companyId)]);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Product options failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Product setup options could not be loaded.'], 500);
        }
    }

    public function createProductCategory(Request $request, array $params): Response
    {
        return $this->createMasterData($request, 'createProductCategory', 'category', 'Product category');
    }

    public function createUomCategory(Request $request, array $params): Response
    {
        return $this->createMasterData($request, 'createUomCategory', 'category', 'Unit category');
    }

    public function createUom(Request $request, array $params): Response
    {
        return $this->createMasterData($request, 'createUom', 'unit', 'Unit of measure');
    }

    public function updateProductCategory(Request $request, array $params): Response
    {
        return $this->mutateMasterData($request, $params, 'updateProductCategory', 'category', 'Product category');
    }

    public function productCategoryStatus(Request $request, array $params): Response
    {
        return $this->mutateMasterData($request, $params, 'setProductCategoryActive', 'category', 'Product category');
    }

    public function updateUomCategory(Request $request, array $params): Response
    {
        return $this->mutateMasterData($request, $params, 'updateUomCategory', 'category', 'Unit category');
    }

    public function uomCategoryStatus(Request $request, array $params): Response
    {
        return $this->mutateMasterData($request, $params, 'setUomCategoryActive', 'category', 'Unit category');
    }

    public function updateUom(Request $request, array $params): Response
    {
        return $this->mutateMasterData($request, $params, 'updateUom', 'unit', 'Unit of measure');
    }

    public function uomStatus(Request $request, array $params): Response
    {
        return $this->mutateMasterData($request, $params, 'setUomActive', 'unit', 'Unit of measure');
    }

    public function updateWarehouse(Request $request, array $params): Response
    {
        return $this->mutateMasterData($request, $params, 'updateWarehouse', 'warehouse', 'Warehouse');
    }

    public function warehouseStatus(Request $request, array $params): Response
    {
        return $this->mutateMasterData($request, $params, 'setWarehouseActive', 'warehouse', 'Warehouse');
    }

    public function createLocation(Request $request, array $params): Response
    {
        return $this->createMasterData($request, 'createLocation', 'location', 'Stock location');
    }

    public function updateLocation(Request $request, array $params): Response
    {
        return $this->mutateMasterData($request, $params, 'updateLocation', 'location', 'Stock location');
    }

    public function locationStatus(Request $request, array $params): Response
    {
        return $this->mutateMasterData($request, $params, 'setLocationActive', 'location', 'Stock location');
    }

    private function createMasterData(Request $request, string $repositoryMethod, string $responseKey, string $label): Response
    {
        if (!Session::verifyCsrf($request->header('x-csrf-token'))) return $this->csrfExpired();
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'inventory.write');
            $record = $repository->{$repositoryMethod}($request->input(), $user, $authorization, $user->id, $request->remoteAddress());
            return Response::json(['ok' => true, $responseKey => $record], 201);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (PDOException $error) {
            error_log($label . ' creation failed: ' . $error->getMessage());
            return $this->conflictOrFailure($error, $label . ' could not be created.');
        } catch (Throwable $error) {
            error_log($label . ' creation failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => $label . ' could not be created.'], 500);
        }
    }

    private function mutateMasterData(Request $request, array $params, string $repositoryMethod, string $responseKey, string $label): Response
    {
        if (!Session::verifyCsrf($request->header('x-csrf-token'))) return $this->csrfExpired();
        try {
            $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1) throw new InvalidArgumentException($label . ' ID must be a positive integer.');
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'inventory.write');
            $record = $repository->{$repositoryMethod}((int)$id, $request->input(), $user, $authorization, $user->id, $request->remoteAddress());
            return Response::json(['ok' => true, $responseKey => $record]);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (PDOException $error) {
            error_log($label . ' update failed: ' . $error->getMessage());
            return $this->conflictOrFailure($error, $label . ' could not be updated.');
        } catch (Throwable $error) {
            error_log($label . ' update failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => $label . ' could not be updated.'], 500);
        }
    }

    public function valuation(Request $request, array $params): Response
    {
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'inventory.read');
            return Response::json(['ok' => true] + $repository->valuation($user, $authorization, $request->query()));
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Inventory valuation report failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Inventory valuation could not be loaded.'], 500);
        }
    }

    public function valuationClosingReport(Request $request, array $params): Response
    {
        try {
            [$user, $authorization] = $this->accountingContext();
            $this->requirePermission($authorization, 'inventory.read');
            $this->requirePermission($authorization, 'accounting.read');
            $repository = new InventoryValuationClosingRepository(Database::connection($this->config));
            return Response::json(['ok' => true, 'report' => $repository->report(
                $request->query()['company_id'] ?? $user->defaultCompanyId,
                $request->query()['as_of_date'] ?? date('Y-m-d'),
                $request->query()['offset'] ?? 0,
                $user,
                $authorization,
            )]);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Inventory valuation closing report failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Inventory valuation closing report could not be loaded.'], 500);
        }
    }

    public function showValuationClosing(Request $request, array $params): Response
    {
        try {
            [$user, $authorization] = $this->accountingContext();
            $this->requirePermission($authorization, 'inventory.read');
            $this->requirePermission($authorization, 'accounting.read');
            $repository = new InventoryValuationClosingRepository(Database::connection($this->config));
            $closing = $repository->find($this->closingId($params), $user, $authorization);
            return $closing
                ? Response::json(['ok' => true, 'closing' => $closing])
                : Response::json(['ok' => false, 'error' => 'Inventory valuation closing was not found.'], 404);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Inventory valuation closing read failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Inventory valuation closing could not be loaded.'], 500);
        }
    }

    public function generateValuationClosing(Request $request, array $params): Response
    {
        if (!Session::verifyCsrf($request->header('x-csrf-token'))) return $this->csrfExpired();
        try {
            [$user, $authorization] = $this->accountingContext();
            $this->requirePermission($authorization, 'inventory.read');
            $this->requirePermission($authorization, 'accounting.read');
            $this->requirePermission($authorization, 'accounting.write');
            $repository = new InventoryValuationClosingRepository(Database::connection($this->config));
            $closing = $repository->generate($request->input(), $user, $authorization, $user->id, $request->remoteAddress());
            return Response::json(['ok' => true, 'closing' => $closing], 201);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (PDOException $error) {
            error_log('Inventory valuation closing generation failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'The inventory closing could not be generated because a linked accounting record or database constraint rejected it.'], 409);
        } catch (Throwable $error) {
            error_log('Inventory valuation closing generation failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Inventory valuation closing could not be generated.'], 500);
        }
    }

    public function postValuationClosing(Request $request, array $params): Response
    {
        if (!Session::verifyCsrf($request->header('x-csrf-token'))) return $this->csrfExpired();
        try {
            [$user, $authorization] = $this->accountingContext();
            $this->requirePermission($authorization, 'inventory.read');
            $this->requirePermission($authorization, 'accounting.read');
            $this->requirePermission($authorization, 'accounting.post');
            $repository = new InventoryValuationClosingRepository(Database::connection($this->config));
            $closing = $repository->post($this->closingId($params), $user, $authorization, $user->id, $request->remoteAddress());
            return Response::json(['ok' => true, 'closing' => $closing]);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (PDOException $error) {
            error_log('Inventory valuation closing post failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'The inventory closing could not be posted because a linked accounting record or database constraint rejected it.'], 409);
        } catch (Throwable $error) {
            error_log('Inventory valuation closing post failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Inventory valuation closing could not be posted.'], 500);
        }
    }

    public function discardValuationClosing(Request $request, array $params): Response
    {
        if (!Session::verifyCsrf($request->header('x-csrf-token'))) return $this->csrfExpired();
        try {
            [$user, $authorization] = $this->accountingContext();
            $this->requirePermission($authorization, 'inventory.read');
            $this->requirePermission($authorization, 'accounting.read');
            $this->requirePermission($authorization, 'accounting.write');
            $repository = new InventoryValuationClosingRepository(Database::connection($this->config));
            $closing = $repository->discard($this->closingId($params), $user, $authorization, $user->id, $request->remoteAddress());
            return Response::json(['ok' => true, 'closing' => $closing]);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (PDOException $error) {
            error_log('Inventory valuation closing discard failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'The inventory closing could not be discarded because a linked accounting record or database constraint rejected it.'], 409);
        } catch (Throwable $error) {
            error_log('Inventory valuation closing discard failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Inventory valuation closing could not be discarded.'], 500);
        }
    }

    public function accountingSettings(Request $request, array $params): Response
    {
        try {
            [$user, $authorization] = $this->accountingContext();
            $this->requirePermission($authorization, 'inventory.read');
            $repository = new InventoryAccountingRepository(Database::connection($this->config));
            return Response::json(['ok' => true, 'accounting' => $repository->read($request->query()['company_id'] ?? $user->defaultCompanyId, $user, $authorization)]);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Inventory accounting settings read failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Inventory accounting setup could not be loaded.'], 500);
        }
    }

    public function saveAccountingSettings(Request $request, array $params): Response
    {
        if (!Session::verifyCsrf($request->header('x-csrf-token'))) return $this->csrfExpired();
        try {
            [$user, $authorization] = $this->accountingContext();
            $this->requirePermission($authorization, 'accounting.write');
            $repository = new InventoryAccountingRepository(Database::connection($this->config));
            $accounting = $repository->save($request->input(), $user, $authorization, $user->id, $request->remoteAddress());
            return Response::json(['ok' => true, 'accounting' => $accounting]);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (PDOException $error) {
            error_log('Inventory accounting settings save failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Inventory accounting setup could not be saved because a linked record or database constraint rejected it.'], 409);
        } catch (Throwable $error) {
            error_log('Inventory accounting settings save failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Inventory accounting setup could not be saved.'], 500);
        }
    }

    public function index(Request $request, array $params): Response
    {
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'inventory.read');
            return Response::json(['ok' => true] + $repository->search($user, $authorization, $request->query()));
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Inventory products list failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Products could not be loaded.'], 500);
        }
    }

    public function catalog(Request $request, array $params): Response
    {
        try {
            [$user, $authorization, $repository] = $this->context();
            return Response::json(['ok' => true] + $repository->catalog($user, $authorization, $request->query()));
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Shared product catalog failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'The product catalog could not be loaded.'], 500);
        }
    }

    public function show(Request $request, array $params): Response
    {
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'inventory.read');
            $product = $repository->find($this->routeId($params), $user, $authorization);
            return $product ? Response::json(['ok' => true, 'product' => $product]) : Response::json(['ok' => false, 'error' => 'Product not found.'], 404);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Inventory product read failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Product could not be loaded.'], 500);
        }
    }

    public function create(Request $request, array $params): Response
    {
        return $this->save($request, null);
    }

    public function update(Request $request, array $params): Response
    {
        try {
            return $this->save($request, $this->routeId($params));
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        }
    }

    public function productImportTemplate(Request $request, array $params): Response
    {
        $stream = null;
        try {
            [$user, $authorization] = $this->accountingContext();
            $companyId = $this->companyId($request->query()['company_id'] ?? null);
            if ($companyId === null || !$authorization->canAccessCompany($companyId)) throw new InvalidArgumentException('Choose an allowed company first.');
            if (!$authorization->allowsForCompany('products.create', $companyId) && !$authorization->allowsForCompany('inventory.write', $companyId)) {
                throw new AccessDeniedException('Product create or Inventory write access is required to download the import template.');
            }
            $includeAccounts = $authorization->allowsForCompany('accounting.read', $companyId);
            $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
            if (!is_resource($stream)) throw new \RuntimeException('The product import template could not be prepared.');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ProductCsv::headers($includeAccounts), ',', '"', '');
            return Response::stream($stream, 'text/csv; charset=utf-8', 200, [
                'Content-Disposition' => 'attachment; filename="products-import-template.csv"',
            ]);
        } catch (AccessDeniedException $error) {
            if (is_resource($stream)) fclose($stream);
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            if (is_resource($stream)) fclose($stream);
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            if (is_resource($stream)) fclose($stream);
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            if (is_resource($stream)) fclose($stream);
            error_log('Product import template failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'The product import template could not be prepared.'], 500);
        }
    }

    public function importProducts(Request $request, array $params): Response
    {
        if (!Session::verifyCsrf($request->header('x-csrf-token'))) return $this->csrfExpired();
        try {
            [$user, $authorization, $repository] = $this->context();
            $input = $request->input();
            $companyId = $this->companyId($input['company_id'] ?? null);
            if ($companyId === null) throw new InvalidArgumentException('Choose a company before importing products.');
            $file = $request->file('file');
            if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)) {
                throw new InvalidArgumentException('Choose a CSV file to import.');
            }
            if ((int)($file['size'] ?? 0) > 5_000_000) throw new InvalidArgumentException('The product CSV must be 5 MB or smaller.');
            $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
            if ($extension !== 'csv' && $extension !== 'txt') throw new InvalidArgumentException('Save the Excel sheet as CSV UTF-8 before importing.');
            $contents = file_get_contents($file['tmp_name']);
            if (!is_string($contents)) throw new InvalidArgumentException('The product CSV could not be read.');
            $rows = ProductCsv::parse($contents);
            $result = $repository->importProducts($rows, $companyId, $user, $authorization, $user->id, $request->remoteAddress());
            return Response::json(['ok' => true, 'created' => $result['created'], 'updated' => $result['updated'], 'total' => $result['created'] + $result['updated']]);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (PDOException $error) {
            error_log('Product CSV import failed: ' . $error->getMessage());
            return $this->conflictOrFailure($error, 'The product import failed. No rows were saved.');
        } catch (Throwable $error) {
            error_log('Product CSV import failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'The product import failed. No rows were saved.'], 500);
        }
    }

    public function createWarehouse(Request $request, array $params): Response
    {
        if (!Session::verifyCsrf($request->header('x-csrf-token'))) {
            return $this->csrfExpired();
        }
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, 'inventory.write');
            $warehouse = $repository->createWarehouse($request->input(), $user, $authorization, $user->id, $request->remoteAddress());
            return Response::json(['ok' => true, 'warehouse' => $warehouse], 201);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (PDOException $error) {
            error_log('Inventory warehouse create failed: ' . $error->getMessage());
            return $this->conflictOrFailure($error, 'Warehouse could not be created.');
        } catch (Throwable $error) {
            error_log('Inventory warehouse create failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Warehouse could not be created.'], 500);
        }
    }

    private function save(Request $request, ?int $id): Response
    {
        if (!Session::verifyCsrf($request->header('x-csrf-token'))) {
            return $this->csrfExpired();
        }
        try {
            [$user, $authorization, $repository] = $this->context();
            $this->requirePermission($authorization, $id === null ? 'products.create' : 'inventory.write');
            $product = $repository->save($id, $request->input(), $user, $authorization, $user->id, $request->remoteAddress());
            return Response::json(['ok' => true, 'product' => $product], $id === null ? 201 : 200);
        } catch (AccessDeniedException $error) {
            return $this->forbidden($error->getMessage());
        } catch (AuthenticationRequiredException $error) {
            return $this->unauthorized($error->getMessage());
        } catch (InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (PDOException $error) {
            error_log('Inventory product save failed: ' . $error->getMessage());
            return $this->conflictOrFailure($error, 'Product could not be saved.');
        } catch (Throwable $error) {
            error_log('Inventory product save failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Product could not be saved.'], 500);
        }
    }

    /** @return array{UserContext, Authorization, InventoryRepository} */
    private function context(): array
    {
        $pdo = Database::connection($this->config);
        $user = (new AuthService($pdo))->currentUser();
        if (!$user) {
            throw new AuthenticationRequiredException('Sign in to use Inventory.');
        }
        return [$user, new Authorization($pdo, $user), new InventoryRepository($pdo)];
    }

    private function accountingContext(): array
    {
        $pdo = Database::connection($this->config);
        $user = (new AuthService($pdo))->currentUser();
        if (!$user) throw new AuthenticationRequiredException('Sign in to use Inventory accounting setup.');
        return [$user, new Authorization($pdo, $user)];
    }

    private function requirePermission(Authorization $authorization, string $permission): void
    {
        if (!$authorization->allows($permission)) {
            throw new AccessDeniedException('You do not have permission to perform this Inventory action.');
        }
    }

    private function routeId(array $params): int
    {
        $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw new InvalidArgumentException('Product ID must be a positive integer.');
        }
        return $id;
    }

    private function closingId(array $params): int
    {
        $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) throw new InvalidArgumentException('Closing ID must be a positive integer.');
        return $id;
    }

    private function companyId(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) throw new InvalidArgumentException('Company ID must be a positive integer.');
        return $id;
    }

    private function conflictOrFailure(PDOException $error, string $message): Response
    {
        $sqlState = (string)($error->errorInfo[0] ?? $error->getCode());
        if (str_starts_with($sqlState, '23')) {
            return Response::json(['ok' => false, 'error' => 'A duplicate or linked record prevents this change. Check the company-scoped name or code and any records that use this value.'], 409);
        }
        return Response::json(['ok' => false, 'error' => $message], 500);
    }

    private function csrfExpired(): Response
    {
        return Response::json(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'], 419);
    }

    private function forbidden(string $message): Response { return Response::json(['ok' => false, 'error' => $message], 403); }
    private function unauthorized(string $message): Response { return Response::json(['ok' => false, 'error' => $message], 401); }
}
