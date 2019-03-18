<?php
/**
 *
 * part-db version 0.1
 * Copyright (C) 2005 Christoph Lechner
 * http://www.cl-projects.de/
 *
 * part-db version 0.2+
 * Copyright (C) 2009 K. Jacobs and others (see authors.php)
 * http://code.google.com/p/part-db/
 *
 * Part-DB Version 0.4+
 * Copyright (C) 2016 - 2019 Jan Böhmer
 * https://github.com/jbtronics
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA
 *
 */

namespace App\Services;


use App\Configuration\PermissionsConfiguration;
use App\Entity\User;
use App\Security\Interfaces\HasPermissionsInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Yaml\Yaml;

class PermissionResolver
{
    protected $permission_structure;


    /**
     *
     * PermissionResolver constructor.
     * @param ParameterBagInterface $params
     */
    public function __construct(ParameterBagInterface $params)
    {
        //Read the permission config file...
        $config = Yaml::parse(
            file_get_contents(__DIR__ . '/../../config/permissions.yaml')
        );


        $configs = [$config];

        //... And parse it
        $processor = new Processor();
        $databaseConfiguration = new PermissionsConfiguration();
        $processedConfiguration = $processor->processConfiguration(
            $databaseConfiguration,
            $configs
        );

        $this->permission_structure = $processedConfiguration;
    }


    /**
     * Check if a user/group is allowed to do the specified operation for the permission.
     *
     * See permissions.yaml for valid permission operation combinations.
     *
     * @param HasPermissionsInterface $user The user/group for which the operation should be checked.
     * @param string $permission The name of the permission for which should be checked.
     * @param string $operation The name of the operation for which should be checked.
     * @return bool|null True, if the user is allowed to do the operation (ALLOW), false if not (DISALLOW), and null,
     * if the value is set to inherit.
     */
    public function dontInherit(HasPermissionsInterface $user, string $permission, string $operation) : ?bool
    {
        //Get the permissions from the user
        $perm_list = $user->getPermissions();

        //Determine bit number using our configuration
        $bit = $this->permission_structure['perms'][$permission]['operations'][$operation]['bit'];

        return $perm_list->getPermissionValue($permission, $bit);
    }


    /**
     * Checks if a user is allowed to do the specified operation for the permission.
     * In contrast to dontInherit() it tries to resolve the inherit values, of the user, by going upwards in the
     * hierachy (user -> group -> parent group -> so on). But even in this case it is possible, that the inherit value
     * could be resolved, and this function returns null.
     *
     * In that case the voter should set it manually to false by using ?? false.
     *
     * @param User $user The user for which the operation should be checked.
     * @param string $permission The name of the permission for which should be checked.
     * @param string $operation The name of the operation for which should be checked.
     * @return bool|null True, if the user is allowed to do the operation (ALLOW), false if not (DISALLOW), and null,
     * if the value is set to inherit.
     */
    public function inherit(User $user, string $permission, string $operation) : ?bool
    {
        //Check if we need to inherit
        $allowed = $this->dontInherit($user, $permission, $operation);

        if ($allowed !== null) {
            //Just return the value of the user.
            return $allowed;
        }

        $parent = $user->getGroup();
        while($parent != null){ //The top group, has parent == null
            //Check if our current element gives a info about disallow/allow
            $allowed = $this->dontInherit($parent, $permission, $operation);
            if ($allowed !== null) {
                return $allowed;
            }
            //Else go up in the hierachy.
            $parent = $parent->getParent();
        }

        return null; //The inherited value is never resolved. Should be treat as false, in Voters.
    }


    /**
     * Lists the names of all operations that is supported for the given permission.
     *
     * If the Permission is not existing at all, a exception is thrown.
     *
     * This function is useful for the support() function of the voters.
     *
     * @param string $permission The permission for which the
     * @return string[] A list of all operations that are supported by the given
     */
    public function listOperationsForPermission(string $permission) : array
    {
        $operations = $this->permission_structure['perms'][$permission]['operations'];

        return array_keys($operations);
    }

    /**
     * Checks if the permission with the given name is existing.
     *
     * @param string $permission The name of the permission which we want to check.
     * @return bool True if a perm with that name is existing. False if not.
     */
    public function isValidPermission(string $permission) : bool
    {
        return isset($this->permission_structure['perms'][$permission]);
    }


}