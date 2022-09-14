<?php

namespace App\Repositories;

use App\Models\Icon;
use App\Models\NexusModel;
use App\Models\SearchBox;
use App\Models\SearchBoxField;
use App\Models\Setting;
use Elasticsearch\Endpoints\Search;
use Illuminate\Support\Arr;
use Nexus\Database\NexusDB;

class SearchBoxRepository extends BaseRepository
{
    public function getList(array $params): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = SearchBox::query();
        list($sortField, $sortType) = $this->getSortFieldAndType($params);
        $query->orderBy($sortField, $sortType);
        return $query->paginate();
    }

    public function store(array $params)
    {
        $result = SearchBox::query()->create($params);
        return $result;
    }

    public function update(array $params, $id)
    {
        $result = SearchBox::query()->findOrFail($id);
        $result->update($params);
        return $result;
    }

    public function getDetail($id)
    {
        $result = SearchBox::query()->findOrFail($id);
        return $result;
    }

    public function delete($id)
    {
        $result = SearchBox::query()->findOrFail($id);
        $success = $result->delete();
        return $success;
    }

    public function buildSearchBox($id)
    {
        $searchBox = SearchBox::query()->with(['categories', 'normal_fields'])->findOrFail($id);
        $fieldData = [];
        foreach ($searchBox->normal_fields as $normalField) {
            $fieldType = $normalField->field_type;
            $info = SearchBoxField::$fieldTypes[$fieldType] ?? null;
            if ($info) {
                /** @var NexusModel $model */
                $model = new $info[$fieldType]['model'];
                $fieldData[$fieldType] = $model::query()->get();
            }
        }
        $searchBox->setRelation('normal_fields_data', $fieldData);
        return $searchBox;
    }

    public function initSearchBoxField($id)
    {
        $searchBox = SearchBox::query()->findOrFail($id);
        $logPrefix = "searchBox: $id";
        $result = $searchBox->normal_fields()->delete();
        do_log("$logPrefix, remove all normal fields: $result");
        foreach (SearchBoxField::$fieldTypes as $fieldType => $info) {
            if ($fieldType == SearchBoxField::FIELD_TYPE_CUSTOM) {
                continue;
            }
            $name = str_replace('_', '', "show{$fieldType}");
            $log = "$logPrefix, name: $name, fieldType: $fieldType";
            if ($searchBox->{$name}) {
                $searchBox->normal_fields()->create([
                    'field_type' => $fieldType,
                ]);
                do_log("$log, create.");
            }
        }
    }

    public function listIcon(array $idArr)
    {
        $searchBoxList = SearchBox::query()->with('categories')->find($idArr);
        if ($searchBoxList->isEmpty()) {
            return $searchBoxList;
        }
        $iconIdArr = [];
        foreach ($searchBoxList as $value) {
            foreach ($value->categories as $category) {
                $iconId = $category->icon_id;
                if (!isset($iconIdArr[$iconId])) {
                    $iconIdArr[$iconId] = $iconId;
                }
            }
        }
        return Icon::query()->find(array_keys($iconIdArr));
    }

    public function migrateToModeRelated()
    {
        $secondIconTable = 'secondicons';
        $normalId = Setting::get('main.browsecat');
        $specialId = Setting::get('main.specialcat');
        $searchBoxList = SearchBox::query()->get();
        $tables = array_values(SearchBox::$taxonomies);

        foreach ($searchBoxList as $searchBox) {
            if ($searchBox->id == $normalId) {
                //all sub categories add `mode` field
                foreach ($tables as  $table) {
                    NexusDB::table($table)->update(['mode' => $normalId]);
                    do_log("update table $table mode = $normalId");
                }
                //also second icons
                NexusDB::table($secondIconTable)->update(['mode' => $normalId]);
                do_log("update table $secondIconTable mode = $normalId");
            } elseif ($searchBox->id == $specialId && $specialId != $normalId) {
                //copy from normal section
                foreach ($tables as $table) {
                    $sql = sprintf(
                        "insert into `%s` (name, sort_index, mode) select name, sort_index, '%s' from `%s`",
                        $table, $specialId, $table
                    );
                    NexusDB::statement($sql);
                    do_log("copy table $table, $sql");
                }
//                $fields = array_keys(SearchBox::$taxonomies);
//                $fields = array_merge($fields, ['name', 'class_name', 'image']);
//                $fieldStr = implode(', ', $fields);
//                $sql = sprintf(
//                    "insert into `%s` (%s, mode) select %s, '%s' from `%s`",
//                    $secondIconTable, $fieldStr, $fieldStr, $specialId, $secondIconTable
//                );
//                NexusDB::statement($sql);
//                do_log("copy table $secondIconTable, $sql");
            }
            $taxonomies = [];
            foreach (SearchBox::$taxonomies as $field => $taxonomyTable) {
                $showField = "show" . $field;
                if ($searchBox->{$showField}) {
                    $taxonomies[] = [
                        'torrent_field' => $field,
                        'display_text' => $field,
                    ];
                }
            }
            $searchBox->update(["extra->" . SearchBox::EXTRA_TAXONOMY_LABELS => $taxonomies]);
        }
    }

    public function renderQualitySelect($searchBox, array $torrentInfo = []): string
    {
        if (!$searchBox instanceof SearchBox) {
            $searchBox = SearchBox::query()->findOrFail(intval($searchBox));
        }
        $results = [];
        foreach (SearchBox::$taxonomies as $torrentField => $table) {
            $searchBoxField = "show" . $torrentField;
            if ($searchBox->{$searchBoxField}) {
                $select = sprintf("<b>%s:</b>", $searchBox->getTaxonomyLabel($torrentField));
                $select .= sprintf('<select name="%s" data-mode="%s">', $torrentField . "_sel", $searchBox->id);
                $select .= sprintf('<option value="%s">%s</option>', 0, nexus_trans('nexus.select_one_please'));
                $relation = "taxonomy_$torrentField";
                $list = $searchBox->{$relation};
                foreach ($list as $item) {
                    $selected = '';
                    if (isset($torrentInfo[$torrentField]) && $torrentInfo[$torrentField] == $item->id) {
                        $selected = " selected";
                    }
                    $select .= sprintf('<option value="%s"%s>%s</option>', $item->id, $selected, $item->name);
                }
                $select .= '</select>';
                $results[] = $select;
            }
        }
        return implode('&nbsp;&nbsp;', $results);
    }

}
