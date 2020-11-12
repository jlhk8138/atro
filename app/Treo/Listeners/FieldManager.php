<?php
/*
 * This file is part of EspoCRM and/or AtroCore.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2019 Yuri Kuznetsov, Taras Machyshyn, Oleksiy Avramenko
 * Website: http://www.espocrm.com
 *
 * AtroCore is EspoCRM-based Open Source application.
 * Copyright (C) 2020 AtroCore UG (haftungsbeschränkt).
 *
 * AtroCore as well as EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * AtroCore as well as EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word
 * and "AtroCore" word.
 */

declare(strict_types=1);

namespace Treo\Listeners;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Utils\Json;
use Treo\Core\EventManager\Event;
use Treo\Core\Utils\Util;

/**
 * Class FieldManager
 */
class FieldManager extends AbstractListener
{
    /**
     * @param Event $event
     *
     * @throws BadRequest
     */
    public function beforeSave(Event $event)
    {
        // get old field defs
        $oldDefs = $event->getArgument('oldFieldDefs');

        if (in_array($oldDefs['type'], ['enum', 'multiEnum'])) {
            $scope = $event->getArgument('scope');
            $field = $event->getArgument('field');

            // get entity defs
            $entityDefs = $this->getMetadata()->get(['entityDefs', $scope]);

            // get current field defs
            $defs = $entityDefs['fields'][$field];

            // get deleted positions
            $deletedPositions = $this->getDeletedPositions($defs['options']);

            // delete positions
            if (!empty($deletedPositions)) {
                $this->deletePositions($defs, $deletedPositions);
            }

            $entityDefs['fields'][$field] = $defs;
            $this->getMetadata()->set('entityDefs', $scope, $entityDefs);

            if ($oldDefs['type'] === 'enum') {
                $this->updateEnumValue($scope, $field, $deletedPositions, $oldDefs, $defs);
            }

            if ($oldDefs['type'] === 'multiEnum') {
                $this->updateMultiEnumValue($scope, $field, $deletedPositions, $oldDefs, $defs);
            }
        }
    }

    /**
     * @param string $scope
     * @param string $field
     * @param array  $deletedPositions
     * @param array  $oldDefs
     * @param array  $defs
     *
     * @return bool
     * @throws BadRequest
     */
    protected function updateEnumValue(string $scope, string $field, array $deletedPositions, array $oldDefs, array $defs): bool
    {
        if (!$this->isEnumTypeValueValid($defs)) {
            return true;
        }

        $tableName = Util::toUnderScore($scope);
        $columnName = Util::toUnderScore($field);

        $sql = [];

        /**
         * First, delete rows
         */
        foreach ($deletedPositions as $pos) {
            foreach ($this->getTypeValuesFields($defs) as $item) {
                // prepare column
                $column = str_replace('options', $columnName, Util::toUnderScore($item));
                $rowSql = "UPDATE $tableName SET $column='' WHERE $column='{$oldDefs[$item][$pos]}' AND deleted=0";

                if ($item != 'options') {
                    $rowSql .= " AND $columnName=''";
                }

                $sql[] = $rowSql;

                unset($oldDefs[$item][$pos]);
                $oldDefs[$item] = array_values($oldDefs[$item]);
            }
        }


        /**
         * Second, update rows
         */
        foreach ($this->getTypeValuesFields($defs) as $item) {
            // prepare column
            $column = str_replace('options', $columnName, Util::toUnderScore($item));

            // get fetched
            $fetchedTypeValue = $oldDefs[$item];

            // get type value and remove deleted positions
            $typeValue = $defs[$item];

            foreach ($oldDefs['options'] as $k => $value) {
                $oldValue = isset($fetchedTypeValue[$k]) ? $fetchedTypeValue[$k] : '';
                if ($oldValue != $typeValue[$k]) {
                    $rowSql = "UPDATE $tableName SET $column='{$typeValue[$k]}' WHERE $column='$oldValue' AND deleted=0";
                    if ($item != 'options') {
                        $rowSql .= " AND $columnName='{$defs['options'][$k]}'";
                    }

                    // push
                    $sql[] = $rowSql;
                }
            }
        }

        /**
         * Third, set to DB
         */
        if (!empty($sql)) {
            $this->getEntityManager()->nativeQuery(implode(';', $sql));
        }

        return true;
    }

    /**
     * @param string $scope
     * @param string $field
     * @param array  $deletedPositions
     * @param array  $oldDefs
     * @param array  $defs
     *
     * @return bool
     * @throws BadRequest
     */
    protected function updateMultiEnumValue(string $scope, string $field, array $deletedPositions, array $oldDefs, array $defs): bool
    {
        if (!$this->isEnumTypeValueValid($defs)) {
            return true;
        }

        $tableName = Util::toUnderScore($scope);
        $columnName = Util::toUnderScore($field);

        // delete
        foreach ($deletedPositions as $deletedPosition) {
            unset($oldDefs['options'][$deletedPosition]);
        }

        // prepare became values
        $becameValues = [];
        foreach (array_values($oldDefs['options']) as $k => $v) {
            $becameValues[$v] = $defs['options'][$k];
        }

        /** @var array $records */
        $records = $this
            ->getEntityManager()
            ->getRepository($scope)
            ->select(['id', $field])
            ->find()
            ->toArray();

        foreach ($records as $record) {
            /**
             * First, prepare main value
             */
            if (!empty($record[$field])) {
                $newValues = [];
                foreach ($record[$field] as $value) {
                    if (isset($becameValues[$value])) {
                        $newValues[] = $becameValues[$value];
                    }
                }
                $record[$field] = $newValues;
            }

            $sqlValues = ["{$columnName}='" . Json::encode($record[$field]) . "'"];

            /**
             * Second, update locales
             */
            if ($this->getConfig()->get('isMultilangActive', false)) {
                foreach ($this->getConfig()->get('inputLanguageList', []) as $language) {
                    $locale = ucfirst(Util::toCamelCase(strtolower($language)));
                    $localeValues = [];
                    foreach ($record[$field] as $value) {
                        $localeValues[] = $defs['options' . $locale][array_search($value, $defs['options'])];
                    }
                    $sqlValues[] = "{$columnName}_" . strtolower($language) . "='" . Json::encode($localeValues) . "'";
                }
            }

            /**
             * Third, set to DB
             */
            $this
                ->getEntityManager()
                ->nativeQuery("UPDATE {$tableName} SET " . implode(",", $sqlValues) . " WHERE id='{$record['id']}'");
        }

        return true;
    }

    /**
     * @param array $typeValue
     *
     * @return array
     */
    protected function getDeletedPositions(array $typeValue): array
    {
        $deletedPositions = [];
        foreach ($typeValue as $pos => $value) {
            if ($value === 'todel') {
                $deletedPositions[] = $pos;
            }
        }

        return $deletedPositions;
    }

    /**
     * @param array $defs
     * @param array $deletedPositions
     */
    protected function deletePositions(array &$defs, array $deletedPositions): void
    {
        foreach ($this->getTypeValuesFields($defs) as $field) {
            $typeValue = $defs[$field];
            foreach ($deletedPositions as $pos) {
                unset($typeValue[$pos]);
            }
            $defs[$field] = array_values($typeValue);
        }
    }

    /**
     * @param array $defs
     *
     * @return array
     */
    protected function getTypeValuesFields(array $defs): array
    {
        $fields[] = 'options';
        if ($this->getConfig()->get('isMultilangActive', false)) {
            foreach ($this->getConfig()->get('inputLanguageList', []) as $locale) {
                $fields[] = 'options' . ucfirst(Util::toCamelCase(strtolower($locale)));
            }
        }

        return $fields;
    }

    /**
     * @param array $defs
     *
     * @return bool
     * @throws BadRequest
     */
    protected function isEnumTypeValueValid(array $defs): bool
    {
        if (!empty($defs)) {
            foreach (array_count_values($defs) as $count) {
                if ($count > 1) {
                    throw new BadRequest($this->exception('Field value should be unique.'));
                }
            }
        }

        return true;
    }

    /**
     * @param string $key
     *
     * @return string
     */
    protected function exception(string $key): string
    {
        return $this->getLanguage()->translate($key, 'exceptions', 'Global');
    }
}
