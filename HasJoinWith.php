<?php

namespace OmniFetch;


use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;

trait HasJoinWith
{
    /**
     * Joins tables using the Model relations
     * @param Builder $builder
     * @param $relations
     * @param array $aliases
     * @param string $type
     * @return Builder
     */
    public function scopeJoinWith(Builder $builder, $relations, $aliases = [], $type = 'inner')
    {
        $count = 0;
        foreach ($relations as $index => $relation){
            $relation_parts = explode('.', $relation);
            $alias_parts  = explode('.', $aliases[$index]);
            $count++;
            for ($i = 0; $i < count($relation_parts); ++$i){
                $nested_relation_arr = array_slice($relation_parts, 0, $i + 1);

                /** @var $relation Relation */
                $relation = Relation::noConstraints(function () use ($nested_relation_arr) {
                    $relation = $this;
                    $nested_count = count($nested_relation_arr);
                    for ($i =0; $i < $nested_count; ++$i){
                        $item = $nested_relation_arr[$i];

                        if (($i + 1) == $nested_count){
                            $relation = $relation->$item();
                        } else {
                            $relation = $relation->$item()->getModel();
                        }
                    }

                    return $relation;
                });

                $table = $relation->getRelated()->getTable();
                if ($relation instanceof BelongsTo) {
                    $one = $relation->getQualifiedForeignKey();
                    $two = $relation->getQualifiedOwnerKeyName();
                } else {
                    /** @var $relation HasOneOrMany */
                    $one = $relation->getQualifiedParentKeyName();
                    $two = $relation->getQualifiedForeignKeyName();
                }

                $parent_alias = (($i - 1) < 0) ? null : $alias_parts[$i - 1];
                $table_alias = $alias_parts[$i];

                $one_parts = explode('.', $one);
                $two_parts = explode('.', $two);

                $one = (($one_parts[0] == $table) ? $table_alias : ((empty($parent_alias)) ? $one_parts[0] : $parent_alias)) . '.' . $one_parts[1];
                $two = (($two_parts[0] == $table) ? $table_alias : ((empty($parent_alias)) ? $two_parts[0] : $parent_alias)) . '.' . $two_parts[1];

                $join_table = (empty($table_alias)) ? $table : "$table AS $table_alias";
                $builder->getQuery()->join($join_table, $one, '=', $two, $type, false);

                if ($relation instanceof Relation) {
                    $relationQuery = $relation->getBaseQuery();
                } elseif ($relation instanceof Builder) {
                    $relationQuery = $relation->getQuery();
                } else {
                    $relationQuery = $relation;
                }

                foreach ($relationQuery->getRawBindings() as $a_type => $value) {
                    $builder->getQuery()->addBinding($value, $a_type);
                }

                foreach (['wheres', 'joins', 'columns'] as $attr) {
                    $builder_attr = (array) $builder->getQuery()->$attr;
                    $relation_attr = (array) $relationQuery->$attr;

                    if ($attr == 'wheres'){
                        foreach ($builder_attr as &$a){
                            if (!empty($a['column'])){
                                $column_parts = explode('.', $a['column']);
                                if (count($column_parts) == 1){
                                    array_unshift($column_parts, $this->getTable());
                                }

                                $a['column'] = (($column_parts[0] == $table) ? $table_alias : ((empty($parent_alias)) ? $column_parts[0] : $parent_alias)) . '.' . $column_parts[1];
                            }
                        }

                        foreach ($relation_attr as &$b){
                            if (!empty($b['column'])){
                                $column_parts = explode('.', $b['column']);
                                if (count($column_parts) == 1){
                                    array_unshift($column_parts, $this->getTable());
                                }

                                $b['column'] = (($column_parts[0] == $table) ? $table_alias : ((empty($parent_alias)) ? $column_parts[0] : $parent_alias)) . '.' . $column_parts[1];
                            }
                        }
                    }

                    $builder->getQuery()->$attr = array_merge(
                        $builder_attr,
                        $relation_attr
                    );
                }
            }
        }

        return $builder;
    }
}