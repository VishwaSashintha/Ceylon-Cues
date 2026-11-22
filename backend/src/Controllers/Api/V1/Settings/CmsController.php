<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Settings;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Core\Validator;
use CeylonCues\Services\Settings\CmsService;
use Exception;

final class CmsController
{
    private CmsService $cms;

    public function __construct()
    {
        $this->cms = new CmsService();
    }

    public function index(Request $request): void
    {
        $pages = $this->cms->listPages(true);
        Response::json($pages, 'CMS pages retrieved');
    }

    public function show(Request $request, string $slug): void
    {
        $page = $this->cms->getPageBySlug($slug);
        if (!$page) {
            Response::notFound('CMS page not found');
        }
        Response::json($page, 'CMS page retrieved');
    }

    public function store(Request $request): void
    {
        $data = $request->getBody();

        $v = Validator::validate($data, [
            'title'   => 'required|string|max:255',
            'slug'    => 'required|string|max:255',
            'content' => 'required|string',
        ]);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $page = $this->cms->createPage($data);
            Response::created($page, 'CMS page created');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function update(Request $request, int $id): void
    {
        $data = $request->getBody();

        try {
            $page = $this->cms->updatePage($id, $data);
            Response::json($page, 'CMS page updated');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function destroy(Request $request, int $id): void
    {
        try {
            $this->cms->deletePage($id);
            Response::json(null, 'CMS page deleted');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }
}
