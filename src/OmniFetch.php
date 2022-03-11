<?php

namespace OmniFetch;


use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class OmniFetch
{
    const DEFAULT_PAGE_SIZE = 20;

    const LOGICAL_OP_AND = 'AND';
    const LOGICAL_OP_OR = 'OR';

    const ORDER_ASC = 'asc';
    const ORDER_DESC = 'desc';

    const LABEL_FILTER = 'filters';
    const LABEL_PAGE_SIZE = 'page_size';
    const LABEL_EMBEDS = 'embeds';
    const LABEL_ORDER_BY = 'order_by';
    const LABEL_GROUP_BY = 'group_by';
    const LABEL_AGGREGATE = 'aggs';
    const LABEL_IS_ASC = 'is_asc';
    const LABEL_NO_PAGES = 'no_pages';
    const LABEL_PAGE = 'page';

    const GROUP_BY_FIELD = 'field';
    const GROUP_BY_FUNC = 'func';
    const GROUP_BY_ALIAS = 'alias';

    const AGG_FIELD = 'field';
    const AGG_FUNC = 'func';
    const AGG_ALIAS = 'alias';

    const FILTER_FIELD = 'field';
    const FILTER_COND_OP = 'cond_op';
    const FILTER_VALUE = 'value';
    const FILTER_LOGICAL_OP = 'logical_op';

    const COND_IS_NULL = 'IS_NULL';
    const COND_IS_NOT_NULL = 'IS_NOT_NULL';
    const COND_HAS_ALL = 'HAS_ALL';

    const JOIN_RELATIONS = 'relations';
    const JOIN_RELATION_ALIASES = 'aliases';

    protected static $functions = [
        'date' => 'DATE({{col}})',
        'month' => 'DATE_FORMAT({{col}}, \'%Y-%m\')',
        'year' => 'YEAR({{col}})',
        'now' => 'NOW()',
        'now_date' => 'DATE(NOW())'
    ];

    protected static $agg_functions = [
        'count' => 'COUNT({{col}})',
        'avg' => 'AVG({{col}})',
        'min' => 'MIN({{col}})',
        'max' => 'MAX({{col}})',
        'sum' => 'SUM({{col}})'
    ];

    /**
     * @var integer
     *
     * The size of a page. Basically the number of items fetched
     */
    protected $page_size;

    /**
     * @var array
     *
     * Adds in related models to the items fetched.
     * These must be valid related models to the primary model that is being paginated.
     */
    protected $embeds;

    /**
     * @var string
     *
     * The column used to order the paginated items
     */
    protected $order_by;

    /**
     * @var integer
     *
     * The current page
     */
    protected $page;

    /**
     * @var mixed
     *
     * the group by clause
     */
    protected $group_by;

    protected $aggs;

    protected $join_with_relations = [];

    /**
     * @var boolean
     *
     * Determines the direction of ordering, either Ascending or descending
     */
    protected $is_asc;

    /**
     * @var boolean
     */
    protected $no_pages;

    /**
     * @var integer
     */
    protected $total_count;

    /**
     * @var boolean
     */
    protected $has_all = false;

    /**
     * @var array
     */
    protected $filters;

    /**
     * @var array
     */
    protected $fields = null;

    /**
     * Fetches data for a single record
     * @param Builder $builder
     * @param array $params
     * @return \Illuminate\Database\Eloquent\Model|null|static
     */
    public function getSingle(Builder $builder, array $params)
    {
        $this->loadParams($builder, $params);
        $builder = $builder->with($this->embeds);
        $builder = $this->buildFilters($builder);

        return $builder->first();
    }

    /**
     * Fetches a list of records
     * @param Builder $builder
     * @param array $params
     * @return array
     */
    public function paginate(Builder $builder, array $params)
    {
        $this->loadParams($builder, $params);

        if (!empty($this->group_by['select']) || !empty($this->aggs)){
            $select = [$builder->getModel()->getTable() . '.*'];

            if (!empty($this->group_by['select'])) {
                foreach ($this->group_by['select'] as $item) {
                    $select[] = $item;
                }
            }

            if (!empty($this->aggs)) {
                foreach ($this->aggs as $item) {
                    $select[] = $item;
                }
            }

            $builder = $builder->select($select);
        }

        if (
            !empty($this->join_with_relations[self::JOIN_RELATIONS])
            && !empty($this->join_with_relations[self::JOIN_RELATION_ALIASES])
        ){
            $builder = $builder->joinWith(
                array_values($this->join_with_relations[self::JOIN_RELATIONS]),
                array_values($this->join_with_relations[self::JOIN_RELATION_ALIASES])
            );
        }

        $builder = $this->buildFilters($builder);

        /** @var Builder $builder */
        $builder = $builder->with($this->embeds);

        if (!empty($this->order_by)){
            $builder->orderBy($this->order_by, ($this->is_asc) ? self::ORDER_ASC : self::ORDER_DESC);
        }

        if (!empty($this->group_by['group'])){
            $builder->groupBy($this->group_by['group']);
        }

        if (!empty($this->aggs) && empty($this->group_by['group'])){
            $this->no_pages = 1;
            $this->total_count = 1;
        } else {
            $this->total_count = (!empty($this->group_by['group'])) ? $builder->get()->count() : $builder->count();
        }


        if (!$this->no_pages){
            if (empty($this->page)){
                $builder->forPage(1, $this->page_size);
            } else {
                $builder->forPage($this->page, $this->page_size);
            }
        }

        return $this->prepareOutput($builder->get());
    }

    /**
     * Build the criteria for the query (i.e. filters)
     * @param Builder $builder
     * @return Builder
     */
    protected function buildFilters($builder)
    {
        $filter_groups = [];
        foreach ($this->filters as $filter){
            $field_parts = explode('.', $filter[self::FILTER_FIELD]);
            $part_count = count($field_parts);
            if ($part_count == 1){
                $filter_groups['*'][] = [
                    self::FILTER_FIELD => $filter[self::FILTER_FIELD],
                    self::FILTER_COND_OP => $filter[self::FILTER_COND_OP],
                    self::FILTER_VALUE => $filter[self::FILTER_VALUE],
                    self::FILTER_LOGICAL_OP => $filter[self::FILTER_LOGICAL_OP]
                ];
            } else if ($part_count > 1){
                $field = $field_parts[$part_count - 1];
                unset($field_parts[$part_count - 1]);

                $embed = implode('.', $field_parts);
                $filter_groups[$embed][] = [
                    self::FILTER_FIELD => $field,
                    self::FILTER_COND_OP => $filter[self::FILTER_COND_OP],
                    self::FILTER_VALUE => $filter[self::FILTER_VALUE],
                    self::FILTER_LOGICAL_OP => $filter[self::FILTER_LOGICAL_OP]
                ];

                if (strtoupper(trim($filter[OmniFetch::FILTER_COND_OP])) == self::COND_HAS_ALL){
                    $this->has_all[$embed] = true;
                }
            }
        }

        foreach ($filter_groups as $group_key => $filters){
            if ($group_key == '*'){
                foreach ($filters as $filter){
                    if ($filter[self::FILTER_COND_OP] == self::COND_HAS_ALL){
                        continue;
                    } else if ($filter[self::FILTER_COND_OP] == self::COND_IS_NULL){
                        $builder->whereNull($filter[self::FILTER_FIELD]);
                    } else if ($filter[self::FILTER_COND_OP] == self::COND_IS_NOT_NULL){
                        $builder->whereNotNull($filter[self::FILTER_FIELD]);
                    } else if (is_array($filter[self::FILTER_VALUE])) {
                        $builder->whereIn($filter[self::FILTER_FIELD], $filter[self::FILTER_VALUE], $filter[self::FILTER_LOGICAL_OP], trim($filter[self::FILTER_COND_OP]) == '!=');
                    } else {
                        $builder->where($filter[self::FILTER_FIELD], $filter[self::FILTER_COND_OP], $filter[self::FILTER_VALUE], $filter[self::FILTER_LOGICAL_OP]);
                    }
                }
            } else {
                $builder->whereHas($group_key, function ($query) use ($filters){
                    foreach ($filters as $filter){
                        if ($filter[self::FILTER_COND_OP] == self::COND_HAS_ALL){
                            $query->where($filter[self::FILTER_FIELD], '!=', $filter[self::FILTER_VALUE]);
                        } else if ($filter[self::FILTER_COND_OP] == self::COND_IS_NULL){
                            $query->whereNull($filter[self::FILTER_FIELD]);
                        } else if ($filter[self::FILTER_COND_OP] == self::COND_IS_NOT_NULL){
                            $query->whereNotNull($filter[self::FILTER_FIELD]);
                        } else if (is_array($filter[self::FILTER_VALUE])) {
                            $query->whereIn($filter[self::FILTER_FIELD], $filter[self::FILTER_VALUE], $filter[self::FILTER_LOGICAL_OP], trim($filter[self::FILTER_COND_OP]) == '!=');
                        } else {
                            $query->where($filter[self::FILTER_FIELD], $filter[self::FILTER_COND_OP], $filter[self::FILTER_VALUE], $filter[self::FILTER_LOGICAL_OP]);
                        }
                    }
                }, (empty($this->has_all[$group_key])) ? '>=' : '=', (empty($this->has_all[$group_key])) ? 1 : 0);
            }
        }

        return $builder;
    }

    /**
     * Loads in all required params
     * @param Builder $builder
     * @param array $params
     */
    protected function loadParams(Builder $builder, $params)
    {
        $this->no_pages = (isset($params[self::LABEL_NO_PAGES]) && intval($params[self::LABEL_NO_PAGES]) > 0);
        $this->page_size = (empty($params[self::LABEL_PAGE_SIZE])) ? self::DEFAULT_PAGE_SIZE : $params[self::LABEL_PAGE_SIZE];
        $this->order_by = (empty($params[self::LABEL_ORDER_BY])) ? null : $params[self::LABEL_ORDER_BY];
        $this->is_asc = (!isset($params[self::LABEL_IS_ASC]) || intval($params[self::LABEL_IS_ASC]) != 0);
        $this->page = (empty($params[self::LABEL_PAGE])) ? null : intval($params[self::LABEL_PAGE]);

        if (empty($params[self::LABEL_EMBEDS])){
            $this->embeds = [];
        } else {
            $embeds = (is_array($params[self::LABEL_EMBEDS])) ? $params[self::LABEL_EMBEDS] : json_decode($params[self::LABEL_EMBEDS], true);
            $this->embeds = (is_array($embeds)) ? $embeds : [];
        }

        if (empty($params[self::LABEL_FILTER])){
            $this->filters = [];
        } else {
            $filters = (is_array($params[self::LABEL_FILTER])) ? $params[self::LABEL_FILTER] : json_decode($params[self::LABEL_FILTER], true);
            $this->filters = (is_array($filters)) ? $this->loadFilters($filters) : [];
        }

        if (empty($params[self::LABEL_GROUP_BY])){
            $this->group_by = [];
        } else {
            $group_by = (is_array($params[self::LABEL_GROUP_BY])) ? $params[self::LABEL_GROUP_BY] : json_decode($params[self::LABEL_GROUP_BY], true);
            $this->group_by = $this->loadGroupBy($builder, $group_by);
        }

        if (empty($params[self::LABEL_AGGREGATE])){
            $this->aggs = [];
        } else {
            $aggs = (is_array($params[self::LABEL_AGGREGATE])) ? $params[self::LABEL_AGGREGATE] : json_decode($params[self::LABEL_AGGREGATE], true);
            $this->aggs = $this->loadAggs($builder, $aggs);
        }
    }

    /**
     * Loads in Aggregations
     * @param Builder $builder
     * @param $aggs
     * @return array
     */
    protected function loadAggs(Builder $builder, $aggs)
    {
        $final_aggs = [];
        $alias_count = 0;
        foreach ($aggs as $agg){
            $validation = Validator::make($agg, [
                OmniFetch::AGG_FIELD => 'required|string|regex:/^[A-Za-z0-9_.]+$/',
                OmniFetch::AGG_FUNC => [
                    'required',
                    Rule::in(array_keys(self::$agg_functions)),
                    'regex:/^[A-Za-z0-9_.]+$/'
                ],
                OmniFetch::AGG_ALIAS => 'required|string|regex:/^[A-Za-z0-9_.]+$/'
            ]);

            if ($validation->fails()) {
                continue;
            }

            $field_paths = explode('.', $agg[OmniFetch::AGG_FIELD]);
            $count = count($field_paths);
            if ($count == 1){
                $agg[OmniFetch::AGG_FIELD] = $builder->getModel()->getTable() . '.'  . $agg[OmniFetch::AGG_FIELD];
            } else {
                $column = array_pop($field_paths);

                $alias_map = [];
                foreach ($field_paths as $field){
                    $alias_map[$field] = 'a_' . ++$alias_count;
                }

                $relation = join('.', $field_paths);

                $agg[OmniFetch::AGG_FIELD] = $alias_map[array_pop($field_paths)] . '.'  . $column;

                $this->join_with_relations[self::JOIN_RELATIONS][$relation] = join('.', array_keys($alias_map));
                $this->join_with_relations[self::JOIN_RELATION_ALIASES][$relation] = join('.', array_values($alias_map));
            }

            $function = str_replace('{{col}}', $agg[OmniFetch::AGG_FIELD], self::$agg_functions[$agg[OmniFetch::AGG_FUNC]]);
            $final_aggs[] = DB::raw("{$function} AS `{$agg[OmniFetch::AGG_ALIAS]}`");

            $this->fields[] = $agg[OmniFetch::AGG_ALIAS];
        }

        return $final_aggs;
    }

    /**
     * Loads in Group-by params
     * @param Builder $builder
     * @param $group_by
     * @return array
     */
    protected function loadGroupBy(Builder $builder, $group_by)
    {
        $final_group_by = [];
        $alias_count = 0;
        foreach ($group_by as $item) {
            $validation = Validator::make($item, [
                OmniFetch::GROUP_BY_FIELD => 'required|string|regex:/^[A-Za-z0-9_.]+$/',
                OmniFetch::GROUP_BY_FUNC => [
                    Rule::in(array_keys(self::$functions)),
                    'regex:/^[A-Za-z0-9_.]+$/'
                ],
                OmniFetch::GROUP_BY_ALIAS => [
                    "required_with_all:" . OmniFetch::GROUP_BY_FUNC,
                    'regex:/^[A-Za-z0-9_.]+$/'
                ]
            ]);

            if ($validation->fails()) {
                continue;
            }

            $field_paths = explode('.', $item[OmniFetch::GROUP_BY_FIELD]);
            $count = count($field_paths);
            if ($count == 1){
                $column = $item[OmniFetch::GROUP_BY_FIELD];
                $item[OmniFetch::GROUP_BY_FIELD] = $builder->getModel()->getTable() . '.'  . $item[OmniFetch::GROUP_BY_FIELD];
            } else {
                $column = array_pop($field_paths);

                $alias_map = [];
                foreach ($field_paths as $field){
                    $alias_map[$field] = 'g_' . ++$alias_count;
                }

                $relation = join('.', $field_paths);

                $item[OmniFetch::GROUP_BY_FIELD] = $alias_map[array_pop($field_paths)] . '.'  . $column;

                $this->join_with_relations[self::JOIN_RELATIONS][$relation] = join('.', array_keys($alias_map));
                $this->join_with_relations[self::JOIN_RELATION_ALIASES][$relation] = join('.', array_values($alias_map));
            }

            if (empty($item[OmniFetch::GROUP_BY_FUNC])) {
                $final_group_by['select'][] = (empty($item[OmniFetch::GROUP_BY_ALIAS])) ? $item[OmniFetch::GROUP_BY_FIELD] : DB::raw("{$item[OmniFetch::GROUP_BY_FIELD]} AS `{$item[OmniFetch::GROUP_BY_ALIAS]}`");
                $final_group_by['group'][] = $item[OmniFetch::GROUP_BY_FIELD];
            } else {
                $function = str_replace('{{col}}', $item[OmniFetch::GROUP_BY_FIELD], self::$functions[$item[OmniFetch::GROUP_BY_FUNC]]);
                $final_group_by['select'][] = DB::raw("{$function} AS `{$item[OmniFetch::GROUP_BY_ALIAS]}`");
                $final_group_by['group'][] =  DB::raw($function);
            }

            $this->fields[] = (empty($item[OmniFetch::GROUP_BY_ALIAS])) ? $column : $item[OmniFetch::GROUP_BY_ALIAS];
        }

        return $final_group_by;
    }

    /**
     * Loads in the Filter params
     * @param $filters
     * @return array
     */
    protected function loadFilters($filters)
    {
        $final_filters = [];
        foreach ($filters as $filter)
        {
            $is_value_required = (
                !empty($filter[OmniFetch::FILTER_COND_OP])
                && in_array(strtoupper(trim($filter[OmniFetch::FILTER_COND_OP])), [self::COND_IS_NOT_NULL, self::COND_IS_NULL])
            );
            $validation = Validator::make($filter, [
                OmniFetch::FILTER_FIELD => 'required|string|regex:/^[A-Za-z0-9_.]+$/',
                OmniFetch::FILTER_VALUE => $is_value_required ? 'nullable' : 'required'
            ]);

            if ($validation->fails()){
                continue;
            }

            $final_filters[] = [
                OmniFetch::FILTER_FIELD => $filter[OmniFetch::FILTER_FIELD],
                OmniFetch::FILTER_VALUE => ($is_value_required) ? null : $filter[OmniFetch::FILTER_VALUE],
                OmniFetch::FILTER_COND_OP => (empty($filter[OmniFetch::FILTER_COND_OP])) ? '=' : strtoupper(trim($filter[OmniFetch::FILTER_COND_OP])),
                OmniFetch::FILTER_LOGICAL_OP => (empty($filter[OmniFetch::FILTER_LOGICAL_OP])) ? OmniFetch::LOGICAL_OP_AND : strtoupper(trim($filter[OmniFetch::FILTER_LOGICAL_OP]))
            ];
        }

        return $final_filters;
    }

    /**
     * Prepares the paginated output
     * @param Collection $items
     * @return array
     */
    protected function prepareOutput(Collection $items)
    {
        $output = [
            'pagination' => [
                'total_count' => $this->total_count
            ]
        ];

        if ($this->page){
            $output['pagination']['total_pages'] = ceil(1.0 * $this->total_count / $this->page_size);
            $output['pagination']['current_page'] = $this->page;
        } else if (!$this->no_pages) {
            $output['pagination']['total_pages'] = ceil(1.0 * $this->total_count / $this->page_size);
            $output['pagination']['current_page'] = 1;
        } else {
            $output['pagination']['total_pages'] = 1;
            $output['pagination']['current_page'] = 1;
        }

        $list = $items->toArray();
        if (is_array($this->fields)){
            foreach ($list as &$item){
                $filter_item = [];
                foreach ($this->fields as $field){
                    $filter_item[$field] = (empty($item[$field])) ? null : $item[$field];
                }

                $item = $filter_item;
            }
        }

        $output['list'] = $list;
        $output['pagination']['count'] = $items->count();

        return $output;
    }
}