<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListCompaniesRequest;
use App\Http\Requests\Admin\StoreCompanyRequest;
use App\Http\Requests\Admin\UpdateCompanyBillingRequest;
use App\Http\Requests\Admin\UpdateCompanyRequest;
use App\Http\Resources\AdminListResource;
use App\Http\Resources\CompanyAdminResource;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\CompanyAdmin;
use Illuminate\Http\JsonResponse;

class CompanyController extends Controller
{
    public function index(ListCompaniesRequest $request): AdminListResource
    {
        $this->authorize('viewAny', Company::class);
        $c = $request->criteria();

        $companies = Company::query()->withAdminCounts(Invoice::today())
            ->when($c['q'], fn ($q, $term) => $q->whereRaw('companies.name ilike ?', ['%'.addcslashes($term, '%_\\').'%']))
            ->orderBy(ltrim($c['sort'], '-'), str_starts_with($c['sort'], '-') ? 'desc' : 'asc')
            ->orderBy('companies.id')
            ->paginate($c['per_page'], ['*'], 'page', $c['page']);

        return new AdminListResource($companies, CompanyAdminResource::class);
    }

    public function show(Company $company, CompanyAdmin $companies): CompanyAdminResource
    {
        $this->authorize('view', $company);

        return new CompanyAdminResource($companies->detail($company->id));
    }

    public function store(StoreCompanyRequest $request, CompanyAdmin $companies): JsonResponse
    {
        $this->authorize('create', Company::class);
        $created = $companies->create($request->user(), ['name' => $request->name()] + $request->billing());

        return (new CompanyAdminResource($companies->detail($created->id)))->response()->setStatusCode(201);
    }

    public function update(UpdateCompanyRequest $request, Company $company, CompanyAdmin $companies): CompanyAdminResource
    {
        $this->authorize('update', $company);
        $companies->update($request->user(), $company, ['name' => $request->name()] + $request->billing(), $request->version());

        return new CompanyAdminResource($companies->detail($company->id));
    }

    public function updateBilling(UpdateCompanyBillingRequest $request, Company $company, CompanyAdmin $companies): CompanyAdminResource
    {
        $this->authorize('updateBilling', $company);
        $companies->update($request->user(), $company, $request->billing(), $request->version());

        return new CompanyAdminResource($companies->detail($company->id));
    }
}
