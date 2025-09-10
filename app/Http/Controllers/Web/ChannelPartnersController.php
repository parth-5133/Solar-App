<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Controllers\API\V1\MenuPermissionsController;
use Illuminate\Http\Request;

class ChannelPartnersController extends Controller
{

    public function index(Request $request)
    {
        $request->merge(['AccessCode' => config('menuAccessCode.CHANNELPARTNERS')]);

        $apiController = new MenuPermissionsController();
        $response = $apiController->index($request);

        $responseData = $response->getData(true);

        $permissions = $responseData['data'] ?? [];

        $menuName = $permissions['menu_name'] ?? '';

        if (empty($permissions) || empty($permissions['canView'])) {
            return response()->view('errors.401',);
        }

        return view('ChannelPartners.index', ['permissions' => $permissions, 'menuName' => $menuName]);
    }

    public function create(Request $request)
    {
        $channelPartnersId = $request->input('id');


        return view('ChannelPartners.create', compact('channelPartnersId'));
    }
}
