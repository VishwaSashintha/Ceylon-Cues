<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Erp;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Core\Validator;
use CeylonCues\Services\Reports\ExpenseService;
use Exception;

/**
 * Operating Expense REST API Controller
 */
final class ExpenseController
{
    private ExpenseService $expenses;

    public function __construct()
    {
        $this->expenses = new ExpenseService();
    }

    /**
     * GET /api/v1/erp/expenses
     */
    public function index(Request $request): void
    {
        $filters = [
            'category'  => $request->getParam('category'),
            'date_from' => $request->getParam('date_from'),
            'date_to'   => $request->getParam('date_to'),
        ];
        $list = $this->expenses->listExpenses($filters);
        Response::json($list, 'Expenses retrieved');
    }

    /**
     * POST /api/v1/erp/expenses
     */
    public function store(Request $request): void
    {
        $user = $request->getUser();
        $data = $request->getBody();

        $v = Validator::validate($data, [
            'category'    => 'required|in:rent,utilities,salaries,marketing,shipping,supplies,maintenance,other',
            'description' => 'required|string',
            'amount'      => 'required|numeric',
        ]);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $exp = $this->expenses->recordExpense($data, (int)$user['id']);
            Response::created($exp, 'Expense recorded successfully');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }
}
