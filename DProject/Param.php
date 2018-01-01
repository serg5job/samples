<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\Param
 *
 * @property integer                                                                $id
 * @property string                                                                 $alias
 * @property integer                                                                $param_group_id
 * @property boolean                                                                $searchable
 * @property boolean                                                                $multiple
 * @method static \Illuminate\Database\Query\Builder|\App\Models\Param whereId($value)
 * @method static \Illuminate\Database\Query\Builder|\App\Models\Param whereAlias($value)
 * @method static \Illuminate\Database\Query\Builder|\App\Models\Param whereParamGroupId($value)
 * @method static \Illuminate\Database\Query\Builder|\App\Models\Param whereSearchable($value)
 * @method static \Illuminate\Database\Query\Builder|\App\Models\Param whereMultiple($value)
 * @mixin \Eloquent
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\ParamValue[] $values
 * @method static \Illuminate\Database\Query\Builder|\App\Models\Param whereConfig($value)
 * @property int                                                                    $depends
 * @method static \Illuminate\Database\Query\Builder|\App\Models\Param whereDepends($value)
 * @property string                                                                 $settings
 * @method static \Illuminate\Database\Query\Builder|\App\Models\Param whereSettings($value)
 */
class Param extends Model
{
    public $timestamps = false;

    protected static $bindNameToParamId = [
        'country' => 1,
        'region' => 2,
        'city' => 3,
        'gender' => 4,
        'birthday' => 5,
        'age' => 5,
        'seek' => 6,
        'look-for' => 7,
    ];

    protected $fillable = ['alias', 'param_group_id', 'searchable', 'multiple'];

    protected $casts = [
        'settings' => 'array'
    ];

    public static function getParamIdByName($name)
    {
        return isset(static::$bindNameToParamId[$name]) ? static::$bindNameToParamId[$name] : false;
    }

    public function values()
    {
        return $this->hasMany(ParamValue::class);
    }

    public function isValueInRange($val)
    {
        if ( ! $this->isRange()) {
            return false;
        }

        $val = intval($val);

        return $this->settings['range']['min'] <= $val && $this->settings['range']['max'] >= $val;
    }

    public function isRange()
    {
        return array_get($this->settings, 'type') === 'int' && isset($this->settings['range']);
    }

    public function isRequired()
    {
        return ! is_null(array_get($this->config, 'required')) ? array_get($this->config, 'required') : true;
    }

}
