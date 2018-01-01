<?php

namespace App\Http\Controllers;

use App\Models\Param;
use App\Models\ParamGroup;
use App\Models\ParamValue;
use App\Models\ParamUser;
use Illuminate\Http\Request;

use App\Http\Requests;
use Illuminate\Support\Facades\Auth;

class ParamsController extends Controller
{

    public function index()
    {

        $paramsData = ParamGroup::where('id', '!=', 1)->gender()->with('params.values')->get();

        $userParams = [];

        foreach (Auth::user()->params as $param) {
            $userParams[$param->param_id][] = $param->value_id;
        }

        return view('params.index', compact('paramsData', 'userParams'));
    }

    public function setValueToParam($param, $val)
    {
        $params = ParamUser::setParams([$param => $val]);

        $paramName = trans('params.param.' . $params->first()->alias . '.name');

        return response(trans('messages.params.updated', ['param' => $paramName]));
    }

    //update all params at once from params page
    public function update(Request $request)
    {
        $paramsData = ParamGroup::gender()->with('params.values')->get();

        $params = [];

        foreach ($paramsData as $paramGroup) {

            /**
             * @var ParamGroup $paramGroup
             */

            foreach ($paramGroup->params as $param) {

                /**
                 * @var Param $param
                 */

                if (empty($request->input('params.' . $param->id))
                    || ! is_array($request->input('params.' . $param->id))) {
                    continue;
                }

                if ($param->isRange()) {

                    $val    = $request->input('params.' . $param->id);
                    $intVal = intval(trim(array_pop($val)));
                    if ($param->settings['range']['min'] <= $intVal
                        && $param->settings['range']['max'] >= $intVal) {
                        $params[$param->id][] = $intVal;
                    }

                    continue;
                }

                foreach ($param->values as $value) {

                    /**
                     * @var ParamValue $value
                     */

                    if (in_array($value->id, $request->input('params.' . $param->id))) {

                        $params[$param->id][] = $value->id;

                        if ( ! $param->multiple) {
                            break;
                        }

                    };

                }
            }
        }

        $alreadySetParams = Auth::user()->paramsList;

        /*
         * remove already set user params from new params
         * and get:
         *  - in $params only not set params
         *  - in $alreadySetParams the rest of params which be set ago and must be deleted
         */
        foreach ($params as $k => $v) {
            foreach ($alreadySetParams as $k2 => $alreadySetParam) {
                //delete params the basic's group
                if(in_array($alreadySetParam->param_id, [1,2,3,4,5,6])) {
                    $alreadySetParams->forget($k2);
                }
                if ($alreadySetParam->param_id == $k && in_array($alreadySetParam->value_id, $v)) {
                    unset($params[$k][array_search($alreadySetParam->value_id, $params[$k])]);
                    if (empty($params[$k])) {
                        unset($params[$k]);
                    }
                    $alreadySetParams->forget($k2);
                }
            }
        }

        //delete redundant params
        if ( ! $alreadySetParams->isEmpty()) {

            $paramsToDelete = \DB::table(ParamUser::$tableName);
            foreach ($alreadySetParams as $v) {
                $paramsToDelete->orWhere(function ($query) use ($v) {
                    return $query->where([
                        'user_id' => $v->user_id,
                        'param_id' => $v->param_id,
                        'value_id' => $v->value_id,
                    ]);
                });
            }

            $paramsToDelete->delete();
        }

        if ( ! empty($params)) {

            $newParams = [];

            foreach ($params as $k => $v) {
                foreach ($v as $v2) {
                    $newParams[] = [
                        'user_id' => Auth::user()->id,
                        'param_id' => $k,
                        'value_id' => $v2,
                    ];
                }
            }

            \DB::table(ParamUser::$tableName)->insert($newParams);

        }

        return redirect()->route('params')->with('success', trans('params.updated'));
    }

}
