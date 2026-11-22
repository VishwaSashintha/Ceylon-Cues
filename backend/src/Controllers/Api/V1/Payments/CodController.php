<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Payments;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Core\Validator;
use CeylonCues\Services\Payments\CodService;
use Exception;

final class CodController
{
    private CodService $cod;

    public function __construct()
    {
        $this->cod = new CodService();
    }

    public function index(Request $request): void
    {
        $remittances = $this->cod->listRemittances();
        Response::json($remittances, 'COD remittances retrieved');
    }

    public function store(Request $request): void
    {
        $data = $request->getBody();

        $v = Validator::validate($data, [
            'order_ids'       => 'required|array',
            'amount'          => 'required|numeric',
            'remittance_date' => 'required|string',
        ]);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $remittance = $this->cod->createRemittance($data);
            Response::created($remittance, 'COD remittance recorded');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function show(Request $request, int $id): void
    {
        $remittance = $this->cod->getRemittance($id);
        if (!$remittance) {
            Response::notFound('COD remittance not found');
        }
        Response::json($remittance, 'COD remittance retrieved');
    }
}
