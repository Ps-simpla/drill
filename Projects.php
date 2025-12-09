<?php

require_once('Simpla.php');

class Projects extends Simpla
{
    private $all_locations = null;
    private $locations_tree = null;
    private $locations_loaded = false;

    // Функция возвращает массив локаций
    public function get_locations($filter = array())
    {
        $this->load_locations();
        
        if (empty($this->all_locations)) {
            return array();
        }

        $locations = $this->all_locations;
        
        // Фильтр по типу
        if (!empty($filter['type'])) {
            $result = array();
            foreach ($locations as $id => $location) {
                if ($location->type == $filter['type']) {
                    $result[$id] = $location;
                }
            }
            return $result;
        }

        // Фильтр по родителю
        if (isset($filter['parent_id'])) {
            $result = array();
            foreach ($locations as $id => $location) {
                if ($location->parent_id == $filter['parent_id']) {
                    $result[$id] = $location;
                }
            }
            return $result;
        }

        return $locations;
    }

    // Загрузка локаций из БД
    protected function load_locations()
    {
        if ($this->locations_loaded) {
            return;
        }
        
        $query = "SELECT * FROM __projects_locations ORDER BY parent_id, position, name";
        $this->db->query($query);
        
        $this->all_locations = array();
        $this->locations_tree = array();
        
        // Получаем все записи
        $rows = $this->db->results();
        
        if (empty($rows)) {
            $this->locations_loaded = true;
            return;
        }
        
        // Создаем индекс по ID и инициализируем свойства
        foreach ($rows as $row) {
            $id = $row->id;
            $this->all_locations[$id] = $row;
            
            // Инициализируем дополнительные свойства
            $this->all_locations[$id]->children = array();
            $this->all_locations[$id]->sublocations = array();
            $this->all_locations[$id]->level = 0;
            $this->all_locations[$id]->path = array();
            
            // Исправляем parent_id если он равен собственному ID (циклическая ссылка)
            if ($row->parent_id == $id) {
                $this->all_locations[$id]->parent_id = null;
            }
        }
        
        // Строим связи детей
        foreach ($this->all_locations as $id => $location) {
            $parent_id = $location->parent_id;
            
            // Проверяем, не ссылается ли объект сам на себя
            if ($parent_id && $parent_id != $id && isset($this->all_locations[$parent_id])) {
                $this->all_locations[$parent_id]->children[] = $id;
            }
        }
        
        // Находим корневые элементы (parent_id = NULL, 0 или несуществующий)
        foreach ($this->all_locations as $id => $location) {
            $parent_id = $location->parent_id;
            
            // Проверяем, является ли элемент корневым
            $is_root = false;
            
            if (empty($parent_id) || $parent_id == 0) {
                $is_root = true;
            } elseif (!isset($this->all_locations[$parent_id])) {
                // Родитель не существует
                $is_root = true;
                // Можно также исправить parent_id
                $this->all_locations[$id]->parent_id = null;
            } elseif ($parent_id == $id) {
                // Циклическая ссылка
                $is_root = true;
                $this->all_locations[$id]->parent_id = null;
            }
            
            if ($is_root) {
                $this->locations_tree[] = $location;
            }
        }
        
        // Рекурсивно строим дерево и вычисляем уровни
        foreach ($this->locations_tree as &$root) {
            $this->build_tree($root, 0);
        }
        
        $this->locations_loaded = true;
    }
    
    // Рекурсивное построение дерева
    private function build_tree(&$node, $level)
    {
        $node->level = $level;
        
        // Очищаем sublocations перед построением
        $node->sublocations = array();
        
        // Добавляем детей
        if (!empty($node->children)) {
            foreach ($node->children as $child_id) {
                if (isset($this->all_locations[$child_id])) {
                    $child = $this->all_locations[$child_id];
                    $node->sublocations[] = $child;
                    $this->build_tree($child, $level + 1);
                }
            }
        }
    }

    // Получить локацию по ID
    public function get_location($id)
    {
        $this->load_locations();
        
        $id = intval($id);
        if ($id && isset($this->all_locations[$id])) {
            return $this->all_locations[$id];
        }
        
        return false;
    }
    
    // Получить локацию по коду
    public function get_location_by_code($code)
    {
        $this->load_locations();
        
        foreach ($this->all_locations as $location) {
            if ($location->code == $code) {
                return $location;
            }
        }
        
        return false;
    }

    // Получить дерево локаций
    public function get_locations_tree()
    {
        $this->load_locations();
        return $this->locations_tree;
    }

    // Получить полную иерархию для локации
    public function get_location_hierarchy($id)
    {
        $location = $this->get_location($id);
        if (!$location) {
            return false;
        }

        $hierarchy = array();
        $current = $location;
        
        // Идем вверх по родителям
        while ($current) {
            $hierarchy[] = $current;
            if ($current->parent_id && isset($this->all_locations[$current->parent_id])) {
                $current = $this->all_locations[$current->parent_id];
            } else {
                $current = null;
            }
        }
        
        return array_reverse($hierarchy);
    }

    // Добавить локацию
    public function add_location($location)
    {
        $location = (array)$location;
        
        // Генерация кода если не указан
        if (empty($location['code']) && !empty($location['name'])) {
            $location['code'] = preg_replace("/[\s]+/ui", '_', $location['name']);
            $location['code'] = strtolower(preg_replace("/[^0-9a-zа-я_]+/ui", '', $location['code']));
        }

        // Проверка уникальности кода
        $counter = 1;
        $original_code = $location['code'];
        while ($this->get_location_by_code($location['code'])) {
            $location['code'] = $original_code . '_' . $counter;
            $counter++;
        }

        $this->db->query("INSERT INTO __projects_locations SET ?%", $location);
        $id = $this->db->insert_id();
        
        // Обновляем позицию если не задана
        if (empty($location['position'])) {
            $this->db->query("UPDATE __projects_locations SET position=id WHERE id=?", $id);
        }
        
        // Сбрасываем кэш
        $this->clear_locations_cache();
        
        return $id;
    }

    // Обновить локацию
    public function update_location($id, $location)
    {
        $location = (array)$location;
        
        // Если меняем код, проверяем уникальность
        if (isset($location['code'])) {
            $current = $this->get_location($id);
            if ($current && $current->code != $location['code']) {
                $counter = 1;
                $original_code = $location['code'];
                while ($existing = $this->get_location_by_code($location['code'])) {
                    if ($existing->id == $id) break;
                    $location['code'] = $original_code . '_' . $counter;
                    $counter++;
                }
            }
        }

        $query = $this->db->placehold("UPDATE __projects_locations SET ?% WHERE id=? LIMIT 1", $location, intval($id));
        $this->db->query($query);
        
        // Сбрасываем кэш
        $this->clear_locations_cache();
        
        return intval($id);
    }

    // Сбросить кэш локаций
    public function clear_locations_cache()
    {
        $this->locations_loaded = false;
        $this->all_locations = null;
        $this->locations_tree = null;
    }

    // Удалить локацию
    public function delete_location($ids)
    {
        $ids = (array) $ids;
        foreach ($ids as $id) {
            $id = intval($id);
            if ($id) {
                $location = $this->get_location($id);
                if ($location) {
                    // Удаляем всех детей
                    if (!empty($location->children)) {
                        $query = $this->db->placehold("DELETE FROM __projects_locations WHERE id IN (?@)", $location->children);
                        $this->db->query($query);
                    }
                    
                    // Удаляем саму локацию
                    $query = $this->db->placehold("DELETE FROM __projects_locations WHERE id=?", $id);
                    $this->db->query($query);
                }
            }
        }
        
        // Сбрасываем кэш
        $this->clear_locations_cache();
        
        return true;
    }

    // Валидация иерархии
    public function validate_hierarchy($parent_id, $type)
    {
        // Правила иерархии
        $hierarchy = array(
            'organization' => null,  // нет родителей
            'tpp' => 'organization',
            'cdng' => 'tpp',
            'field' => 'cdng',
            'cluster' => 'field'
        );
        
        if (!isset($hierarchy[$type])) {
            return false;
        }
        
        // Если parent_id пустой или 0
        if (empty($parent_id)) {
            // Проверяем, может ли этот тип быть корневым
            return $hierarchy[$type] === null;
        }
        
        // Если parent_id указан, получаем родителя
        $parent = $this->get_location($parent_id);
        if (!$parent) {
            return false;
        }
        
        // Проверяем, соответствует ли тип родителя ожидаемому
        return $parent->type == $hierarchy[$type];
    }

    /**
     * Получить локации определенного типа
     */
    public function get_locations_by_type($type)
    {
        $this->load_locations();
        
        $result = array();
        foreach ($this->all_locations as $id => $location) {
            if ($location->type == $type) {
                $result[$id] = $location;
            }
        }
        
        return $result;
    }

    /**
     * Получить все организации (корневые элементы)
     */
    public function get_organizations()
    {
        $this->load_locations();
        
        $organizations = array();
        foreach ($this->all_locations as $id => $location) {
            if ($location->type == 'organization' && empty($location->parent_id)) {
                $organizations[$id] = $location;
            }
        }
        
        return $organizations;
    }

    /**
     * Получить все кусты для конкретного месторождения
     */
    public function get_clusters_by_field($field_id)
    {
        $this->load_locations();
        
        $clusters = array();
        foreach ($this->all_locations as $id => $location) {
            if ($location->type == 'cluster' && $location->parent_id == $field_id) {
                $clusters[$id] = $location;
            }
        }
        
        return $clusters;
    }

    /**
     * Получить все кусты (clusters) с координатами
     */
    public function get_clusters_with_coordinates()
    {
        $this->load_locations();
        
        $clusters = array();
        foreach ($this->all_locations as $id => $location) {
            if ($location->type == 'cluster' && !empty($location->coordinates)) {
                $clusters[$id] = $location;
            }
        }
        
        return $clusters;
    }






    
    // === МЕТОДЫ ДЛЯ СКВАЖИН ===

    /**
     * Получить скважину по ID
     */
    public function get_well($id)
    {
        $query = $this->db->placehold("SELECT * FROM __projects_wells WHERE id=? LIMIT 1", intval($id));
        $this->db->query($query);
        return $this->db->result();
    }

    /**
     * Получить скважины с фильтрами
     */
    public function get_wells($filter = array())
    {
        $where = array();
        
        if (!empty($filter['cluster_id'])) {
            $where[] = $this->db->placehold("cluster_id=?", intval($filter['cluster_id']));
        }
        
        if (!empty($filter['status'])) {
            $where[] = $this->db->placehold("status=?", $filter['status']);
        }
        
        if (isset($filter['visible'])) {
            $where[] = $this->db->placehold("visible=?", intval($filter['visible']));
        }
        
        $where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
        
        $query = $this->db->placehold("SELECT * FROM __projects_wells $where_sql ORDER BY position, id");
        $this->db->query($query);
        return $this->db->results();
    }

    /**
     * Получить скважину по коду
     */
    public function get_well_by_code($code)
    {
        $query = $this->db->placehold("SELECT * FROM __projects_wells WHERE code=? LIMIT 1", $code);
        $this->db->query($query);
        return $this->db->result();
    }

    /**
     * Добавить скважину
     */
    public function add_well($well)
    {
        $well = (array)$well;
        
        // Генерация кода если не указан
        if (empty($well['code']) && !empty($well['name'])) {
            $well['code'] = preg_replace("/[\s]+/ui", '_', $well['name']);
            $well['code'] = strtolower(preg_replace("/[^0-9a-zа-я_]+/ui", '', $well['code']));
        }
        
        // Проверка уникальности кода
        $counter = 1;
        $original_code = $well['code'];
        while ($this->get_well_by_code($well['code'])) {
            $well['code'] = $original_code . '_' . $counter;
            $counter++;
        }
        
        $this->db->query("INSERT INTO __projects_wells SET ?%", $well);
        $id = $this->db->insert_id();
        
        // Обновляем позицию если не задана
        if (empty($well['position'])) {
            $this->db->query("UPDATE __projects_wells SET position=id WHERE id=?", $id);
        }
        
        return $id;
    }

    /**
     * Обновить скважину
     */
    public function update_well($id, $well)
    {
        $well = (array)$well;
        
        // Если меняем код, проверяем уникальность
        if (isset($well['code'])) {
            $current = $this->get_well($id);
            if ($current && $current->code != $well['code']) {
                $counter = 1;
                $original_code = $well['code'];
                while ($existing = $this->get_well_by_code($well['code'])) {
                    if ($existing->id == $id) break;
                    $well['code'] = $original_code . '_' . $counter;
                    $counter++;
                }
            }
        }
        
        $query = $this->db->placehold("UPDATE __projects_wells SET ?% WHERE id=? LIMIT 1", $well, intval($id));
        $this->db->query($query);
        
        return intval($id);
    }

    /**
     * Удалить скважину
     */
    public function delete_well($ids)
    {
        $ids = (array) $ids;
        foreach ($ids as $id) {
            $id = intval($id);
            if ($id) {
                // Сначала удаляем стволы
                $query = $this->db->placehold("DELETE FROM __projects_boreholes WHERE well_id=?", $id);
                $this->db->query($query);
                
                // Удаляем параметры скважины
                $query = $this->db->placehold("DELETE FROM __projects_well_parameter_values WHERE well_id=?", $id);
                $this->db->query($query);
                
                // Удаляем скважину
                $query = $this->db->placehold("DELETE FROM __projects_wells WHERE id=? LIMIT 1", $id);
                $this->db->query($query);
            }
        }
        
        return true;
    }

    /**
     * Получить скважины для куста с полной информацией
     */
    public function get_wells_by_cluster($cluster_id)
    {
        $wells = $this->get_wells(['cluster_id' => $cluster_id]);
        
        foreach ($wells as &$well) {
            // Добавляем стволы
            $well->boreholes = $this->get_boreholes_by_well($well->id);
            
            // Добавляем количество стволов
            $well->boreholes_count = count($well->boreholes);
            
            // Добавляем иерархию локации
            $well->location_hierarchy = $this->get_location_hierarchy($cluster_id);
        }
        
        return $wells;
    }

    /**
     * Получить полную информацию о скважине
     */
    public function get_well_full_info($well_id)
    {
        $well = $this->get_well($well_id);
        if (!$well) {
            return false;
        }
        
        // Получаем куст
        $cluster = $this->get_location($well->cluster_id);
        
        // Получаем полную иерархию
        $hierarchy = $this->get_location_hierarchy($well->cluster_id);
        
        // Получаем стволы
        $boreholes = $this->get_boreholes_by_well($well_id);
        
        // Формируем результат
        $result = (array)$well;
        $result['cluster'] = $cluster;
        $result['hierarchy'] = $hierarchy;
        $result['boreholes'] = $boreholes;
        
        return (object)$result;
    }

    /**
     * Получить статистику по кусту
     */
    public function get_cluster_statistics($cluster_id)
    {
        $stats = [
            'total_wells' => 0,
            'by_status' => [],
            'by_category' => [],
            'by_purpose' => []
        ];
        
        $query = $this->db->placehold("
            SELECT 
                COUNT(*) as total,
                status,
                category,
                purpose
            FROM __projects_wells 
            WHERE cluster_id=? AND visible=1
            GROUP BY status, category, purpose
        ", intval($cluster_id));
        
        $this->db->query($query);
        $results = $this->db->results();
        
        if ($results) {
            foreach ($results as $row) {
                $stats['total_wells'] += $row->total;
                
                if ($row->status) {
                    if (!isset($stats['by_status'][$row->status])) {
                        $stats['by_status'][$row->status] = 0;
                    }
                    $stats['by_status'][$row->status] += $row->total;
                }
                
                if ($row->category) {
                    if (!isset($stats['by_category'][$row->category])) {
                        $stats['by_category'][$row->category] = 0;
                    }
                    $stats['by_category'][$row->category] += $row->total;
                }
                
                if ($row->purpose) {
                    if (!isset($stats['by_purpose'][$row->purpose])) {
                        $stats['by_purpose'][$row->purpose] = 0;
                    }
                    $stats['by_purpose'][$row->purpose] += $row->total;
                }
            }
        }
        
        return $stats;
    }

    // === МЕТОДЫ ДЛЯ СТВОЛОВ ===

    /**
     * Получить ствол по ID
     */
    public function get_borehole($id)
    {
        $query = $this->db->placehold("SELECT * FROM __projects_boreholes WHERE id=? LIMIT 1", intval($id));
        $this->db->query($query);
        return $this->db->result();
    }

    /**
     * Получить стволы скважины
     */
    public function get_boreholes_by_well($well_id)
    {
        $query = $this->db->placehold("SELECT * FROM __projects_boreholes WHERE well_id=? ORDER BY number", intval($well_id));
        $this->db->query($query);
        return $this->db->results();
    }

    /**
     * Добавить ствол
     */
    public function add_borehole($borehole)
    {
        $borehole = (array)$borehole;
        
        // Проверяем обязательные поля
        if (empty($borehole['well_id'])) {
            return false;
        }
        
        // Определяем номер ствола если не указан
        if (empty($borehole['number']) || $borehole['number'] == 0) {
            $existing = $this->get_boreholes_by_well($borehole['well_id']);
            $borehole['number'] = count($existing) + 1;
        }
        
        // Проверяем уникальность номера в пределах скважины
        $query = $this->db->placehold("SELECT id FROM __projects_boreholes WHERE well_id=? AND number=?", 
            $borehole['well_id'], $borehole['number']);
        $this->db->query($query);
        if ($this->db->result()) {
            // Номер уже существует, увеличиваем
            $borehole['number'] = $borehole['number'] + 1;
        }
        
        // Генерация имени если не указано
        if (empty($borehole['name'])) {
            $well = $this->get_well($borehole['well_id']);
            $borehole['name'] = $well ? $well->name . ' Ствол ' . $borehole['number'] : 'Ствол ' . $borehole['number'];
        }
        
        $this->db->query("INSERT INTO __projects_boreholes SET ?%", $borehole);
        return $this->db->insert_id();
    }

    /**
     * Обновить ствол
     */
    public function update_borehole($id, $borehole)
    {
        $borehole = (array)$borehole;
        
        // Проверяем уникальность номера если он изменен
        if (isset($borehole['number'])) {
            $current = $this->get_borehole($id);
            if ($current && $current->number != $borehole['number']) {
                $query = $this->db->placehold("SELECT id FROM __projects_boreholes WHERE well_id=? AND number=? AND id!=?", 
                    $current->well_id, $borehole['number'], $id);
                $this->db->query($query);
                if ($this->db->result()) {
                    // Номер уже существует
                    return false;
                }
            }
        }
        
        $query = $this->db->placehold("UPDATE __projects_boreholes SET ?% WHERE id=? LIMIT 1", $borehole, intval($id));
        $this->db->query($query);
        return intval($id);
    }

    /**
     * Удалить ствол
     */
    public function delete_borehole($ids)
    {
        $ids = (array) $ids;
        foreach ($ids as $id) {
            $query = $this->db->placehold("DELETE FROM __projects_boreholes WHERE id=? LIMIT 1", intval($id));
            $this->db->query($query);
        }
        return true;
    }

    /**
     * Получить полную информацию о стволе
     */
    public function get_borehole_full_info($borehole_id)
    {
        $borehole = $this->get_borehole($borehole_id);
        if (!$borehole) {
            return false;
        }
        
        // Получаем скважину
        $well = $this->get_well($borehole->well_id);
        
        // Получаем иерархию
        if ($well) {
            $hierarchy = $this->get_location_hierarchy($well->cluster_id);
        } else {
            $hierarchy = array();
        }
        
        // Формируем результат
        $result = (array)$borehole;
        $result['well'] = $well;
        $result['hierarchy'] = $hierarchy;
        
        return (object)$result;
    }

    // === МЕТОДЫ ДЛЯ ПАРАМЕТРОВ (если нужны) ===

   

    /**
     * Получить группы параметров
     */
    public function get_parameter_groups($filter = array())
    {
        $where = array();
        
        if (isset($filter['is_active'])) {
            $where[] = $this->db->placehold("is_active=?", intval($filter['is_active']));
        }
        
        $where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
        
        $query = $this->db->placehold("SELECT * FROM __projects_parameter_groups $where_sql ORDER BY sort_order, name");
        $this->db->query($query);
        return $this->db->results();
    }

    /**
     * Получить группу параметров по ID
     */
    public function get_parameter_group($id)
    {
        $query = $this->db->placehold("SELECT * FROM __projects_parameter_groups WHERE id=? LIMIT 1", intval($id));
        $this->db->query($query);
        return $this->db->result();
    }

    /**
     * Добавить группу параметров
     */
    public function add_parameter_group($group)
    {
        $group = (array)$group;
        
        if (empty($group['code']) && !empty($group['name'])) {
            $group['code'] = $this->generate_code($group['name']);
        }
        
        // Проверка уникальности кода
        $counter = 1;
        $original_code = $group['code'];
        while ($this->get_parameter_group_by_code($group['code'])) {
            $group['code'] = $original_code . '_' . $counter;
            $counter++;
        }
        
        $this->db->query("INSERT INTO __projects_parameter_groups SET ?%", $group);
        return $this->db->insert_id();
    }

    /**
     * Обновить группу параметров
     */
    public function update_parameter_group($id, $group)
    {
        $group = (array)$group;
        
        // Если меняем код, проверяем уникальность
        if (isset($group['code'])) {
            $current = $this->get_parameter_group($id);
            if ($current && $current->code != $group['code']) {
                $counter = 1;
                $original_code = $group['code'];
                while ($existing = $this->get_parameter_group_by_code($group['code'])) {
                    if ($existing->id == $id) break;
                    $group['code'] = $original_code . '_' . $counter;
                    $counter++;
                }
            }
        }
        
        $query = $this->db->placehold("UPDATE __projects_parameter_groups SET ?% WHERE id=? LIMIT 1", $group, intval($id));
        $this->db->query($query);
        return intval($id);
    }

    /**
     * Удалить группу параметров
     */
    public function delete_parameter_group($id)
    {
        $query = $this->db->placehold("DELETE FROM __projects_parameter_groups WHERE id=? LIMIT 1", intval($id));
        $this->db->query($query);
        return true;
    }

    /**
     * Получить группу параметров по коду
     */
    public function get_parameter_group_by_code($code)
    {
        $query = $this->db->placehold("SELECT * FROM __projects_parameter_groups WHERE code=? LIMIT 1", $code);
        $this->db->query($query);
        return $this->db->result();
    }

    /**
     * Получить параметры
     */
    public function get_parameters($filter = array())
    {
        $where = array();
        
        if (isset($filter['is_active'])) {
            $where[] = $this->db->placehold("p.is_active=?", intval($filter['is_active']));
        }
        
        if (!empty($filter['group_id'])) {
            $where[] = $this->db->placehold("p.group_id=?", intval($filter['group_id']));
        }
        
        $where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
        
        $query = $this->db->placehold("
            SELECT p.*, g.name as group_name, g.code as group_code
            FROM __projects_parameters p
            LEFT JOIN __projects_parameter_groups g ON g.id = p.group_id
            $where_sql
            ORDER BY g.sort_order, p.sort_order, p.name
        ");
        
        $this->db->query($query);
        return $this->db->results();
    }

    /**
     * Получить параметр по ID
     */
    public function get_parameter($id)
    {
        $query = $this->db->placehold("
            SELECT p.*, g.name as group_name, g.code as group_code
            FROM __projects_parameters p
            LEFT JOIN __projects_parameter_groups g ON g.id = p.group_id
            WHERE p.id=? LIMIT 1
        ", intval($id));
        
        $this->db->query($query);
        return $this->db->result();
    }

    /**
     * Добавить параметр
     */
    public function add_parameter($parameter)
    {
        $parameter = (array)$parameter;
        
        if (empty($parameter['code']) && !empty($parameter['name'])) {
            $parameter['code'] = $this->generate_code($parameter['name']);
        }
        
        // Проверка уникальности кода
        $counter = 1;
        $original_code = $parameter['code'];
        while ($this->get_parameter_by_code($parameter['code'])) {
            $parameter['code'] = $original_code . '_' . $counter;
            $counter++;
        }
        
        $this->db->query("INSERT INTO __projects_parameters SET ?%", $parameter);
        return $this->db->insert_id();
    }

    /**
     * Обновить параметр
     */
    public function update_parameter($id, $parameter)
    {
        $parameter = (array)$parameter;
        
        // Если меняем код, проверяем уникальность
        if (isset($parameter['code'])) {
            $current = $this->get_parameter($id);
            if ($current && $current->code != $parameter['code']) {
                $counter = 1;
                $original_code = $parameter['code'];
                while ($existing = $this->get_parameter_by_code($parameter['code'])) {
                    if ($existing->id == $id) break;
                    $parameter['code'] = $original_code . '_' . $counter;
                    $counter++;
                }
            }
        }
        
        $query = $this->db->placehold("UPDATE __projects_parameters SET ?% WHERE id=? LIMIT 1", $parameter, intval($id));
        $this->db->query($query);
        return intval($id);
    }

    /**
     * Удалить параметр
     */
    public function delete_parameter($id)
    {
        // Сначала удаляем значения параметра
        $query = $this->db->placehold("DELETE FROM __projects_parameter_values WHERE parameter_id=?", intval($id));
        $this->db->query($query);
        
        // Затем удаляем сам параметр
        $query = $this->db->placehold("DELETE FROM __projects_parameters WHERE id=? LIMIT 1", intval($id));
        $this->db->query($query);
        return true;
    }

    /**
     * Получить параметр по коду
     */
    public function get_parameter_by_code($code)
    {
        $query = $this->db->placehold("SELECT * FROM __projects_parameters WHERE code=? LIMIT 1", $code);
        $this->db->query($query);
        return $this->db->result();
    }

    /**
     * Получить значения параметров для скважины
     */
    public function get_parameter_values($well_id)
    {
        $query = $this->db->placehold("
            SELECT v.*, p.name, p.code, p.data_type, p.unit, 
                p.group_id, g.name as group_name, g.code as group_code
            FROM __projects_parameter_values v
            JOIN __projects_parameters p ON p.id = v.parameter_id
            LEFT JOIN __projects_parameter_groups g ON g.id = p.group_id
            WHERE v.well_id = ?
            ORDER BY g.sort_order, p.sort_order, p.name
        ", intval($well_id));
        
        $this->db->query($query);
        $values = $this->db->results();
        
        // Форматируем значения
        $result = array();
        foreach ($values as $value) {
            $formatted_value = null;
            
            switch ($value->data_type) {
                case 'string':
                case 'enum':
                    $formatted_value = $value->string_value;
                    break;
                case 'integer':
                    $formatted_value = $value->integer_value;
                    break;
                case 'decimal':
                    $formatted_value = $value->decimal_value;
                    break;
                case 'boolean':
                    $formatted_value = (bool)$value->boolean_value;
                    break;
                case 'date':
                    $formatted_value = $value->date_value;
                    break;
            }
            
            $result[$value->code] = [
                'id' => $value->id,
                'parameter_id' => $value->parameter_id,
                'name' => $value->name,
                'value' => $formatted_value,
                'unit' => $value->unit,
                'data_type' => $value->data_type,
                'group_id' => $value->group_id,
                'group_name' => $value->group_name,
                'group_code' => $value->group_code
            ];
        }
        
        return $result;
    }

    /**
     * Установить значение параметра для скважины
     */
    public function set_parameter_value($well_id, $parameter_id, $value)
    {
        // Получаем информацию о параметре
        $parameter = $this->get_parameter($parameter_id);
        if (!$parameter) {
            return false;
        }
        
        // Подготавливаем данные в зависимости от типа
        $data = [
            'well_id' => $well_id,
            'parameter_id' => $parameter_id
        ];
        
        switch ($parameter->data_type) {
            case 'string':
            case 'enum':
                $data['string_value'] = (string)$value;
                break;
            case 'integer':
                $data['integer_value'] = (int)$value;
                break;
            case 'decimal':
                $data['decimal_value'] = (float)$value;
                break;
            case 'boolean':
                $data['boolean_value'] = (bool)$value;
                break;
            case 'date':
                $data['date_value'] = $value;
                break;
        }
        
        // Проверяем существование записи
        $query = $this->db->placehold("SELECT id FROM __projects_parameter_values WHERE well_id=? AND parameter_id=?", 
            $well_id, $parameter_id);
        $this->db->query($query);
        $existing = $this->db->result();
        
        if ($existing) {
            // Обновляем существующую запись
            $query = $this->db->placehold("UPDATE __projects_parameter_values SET ?% WHERE id=? LIMIT 1", 
                $data, $existing->id);
        } else {
            // Создаем новую запись
            $query = $this->db->placehold("INSERT INTO __projects_parameter_values SET ?%", $data);
        }
        
        $this->db->query($query);
        return true;
    }

    /**
     * Генерация кода из названия
     */
    private function generate_code($name)
    {
        $code = preg_replace("/[\s]+/ui", '_', $name);
        $code = strtolower(preg_replace("/[^0-9a-zа-я_]+/ui", '', $code));
        return $code;
    }



}