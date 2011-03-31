<?php
###############################################################
#         Simple Desk Project - www.simpledesk.net            #
###############################################################
#       An advanced help desk modifcation built on SMF        #
###############################################################
#                                                             #
#         * Copyright 2010 - SimpleDesk.net                   #
#                                                             #
#   This file and its contents are subject to the license     #
#   included with this distribution, license.txt, which       #
#   states that this software is New BSD Licensed.            #
#   Any questions, please contact SimpleDesk.net              #
#                                                             #
###############################################################
# SimpleDesk Version: 1.0 Felidae                             #
# File Info: SimpleDesk-AdminPermissions.php / 1.0 Felidae    #
###############################################################

/**
 *	This file handles the core of SimpleDesk's permissions system.
 *
 *	@package source
 *	@since 1.1
*/
if (!defined('SMF'))
	die('Hacking attempt...');

/**
 *	This function is the start point for configuration of permissions within SimpleDesk.
 *
 *	@since 1.1
*/
function shd_admin_permissions()
{
	global $context, $scripturl, $sourcedir, $settings, $txt, $modSettings;

	shd_load_all_permission_sets();
	loadTemplate('sd_template/SimpleDesk-AdminPermissions');
	shd_load_language('SimpleDeskPermissions');

	$subactions = array(
		'main' => 'shd_admin_role_list',
		'createrole' => 'shd_admin_create_role',
		'editrole' => 'shd_admin_edit_role',
		'saverole' => 'shd_admin_save_role',
		'copyrole' => 'shd_admin_copy_role',
	);

	$_REQUEST['sa'] = isset($_REQUEST['sa']) && isset($subactions[$_REQUEST['sa']]) ? $_REQUEST['sa'] : 'main';

	$subactions[$_REQUEST['sa']]();
}

/**
 *	This function handles displaying a list of roles known to the system.
 *
 *	@since 1.1
*/
function shd_admin_role_list()
{
	global $context, $txt, $smcFunc;

	$context['page_title'] = $txt['shd_admin_permissions'];
	$context['sub_template'] = 'shd_permissions_home';

	shd_load_role();
}

/**
 *	This function deals with creating a new role in the database, based on a specified template.
 *
 *	@since 1.1
*/
function shd_admin_create_role()
{
	global $context, $txt, $smcFunc;

	$_REQUEST['template'] = isset($_REQUEST['template']) ? (int) $_REQUEST['template'] : 0;
	if (empty($context['shd_permissions']['roles'][$_REQUEST['template']]))
		fatal_lang_error('shd_unknown_template', false);

	if (empty($_REQUEST['part']))
	{
		$context['page_title'] = $txt['shd_create_role'];
		$context['sub_template'] = 'shd_create_role';
		checkSubmitOnce('register');
	}
	else
	{
		checkSubmitOnce('check');
		checkSession();

		// Boring stuff like session checks done. Were you a naughty admin and didn't set it properly?
		if (!isset($_POST['rolename']) || $smcFunc['htmltrim']($smcFunc['htmlspecialchars']($_POST['rolename'])) === '')
			fatal_lang_error('shd_no_role_name', false);
		else
			$_POST['rolename'] = strtr($smcFunc['htmlspecialchars']($_POST['rolename']), array("\r" => '', "\n" => '', "\t" => ''));

		// So here we are, template id is valid, we're good little admins and specified a name, so let's create the new role in the DB.
		$smcFunc['db_insert']('insert',
			'{db_prefix}helpdesk_roles',
			array(
				'template' => 'int', 'role_name' => 'string',
			),
			array(
				$_REQUEST['template'], $_POST['rolename'],
			),
			array(
				'id_role',
			)
		);

		$newrole = $smcFunc['db_insert_id']('{db_prefix}helpdesk_roles', 'id_role');
		if (empty($newrole))
			fatal_lang_error('shd_could_not_create_role', false);

		// Take them to the edit screen!
		redirectexit('action=admin;area=helpdesk_permissions;sa=editrole;role=' . $newrole);
	}
}

function shd_admin_edit_role()
{
	global $context, $txt, $smcFunc, $scripturl;

	$_REQUEST['role'] = isset($_REQUEST['role']) ? (int) $_REQUEST['role'] : 0;
	shd_load_role($_REQUEST['role']);

	if (empty($context['shd_permissions']['user_defined_roles'][$_REQUEST['role']]))
		fatal_lang_error('shd_unknown_role', false);

	// OK, figure out what groups are possible groups (including regular members), and what groups this role has.
	// We're not interested in admin (group 1), board mod (group 3) or post count groups (min_posts != -1)
	$context['membergroups'][0] = array(
		'group_name' => $txt['membergroups_members'],
		'color' => '',
		'link' => $txt['membergroups_members'],
		'stars' => '',
	);

	$query = $smcFunc['db_query']('', '
		SELECT mg.id_group, mg.group_name, mg.online_color, mg.stars
		FROM {db_prefix}membergroups AS mg
		WHERE mg.min_posts = -1
			AND mg.id_group NOT IN (1,3)
		ORDER BY id_group',
		array()
	);

	while ($row = $smcFunc['db_fetch_assoc']($query))
	{
		$context['membergroups'][$row['id_group']] = array(
			'name' => $row['group_name'],
			'color' => $row['online_color'],
			'link' => '<a href="' . $scripturl . '?action=groups;sa=members;group=' . $row['id_group'] . '"' . (empty($row['online_color']) ? '' : ' style="color: ' . $row['online_color'] . ';"') . '>' . $row['group_name'] . '</a>',
			'stars' => $row['stars'],
		);
	}

	$smcFunc['db_free_result']($query);

	// Now for this role's groups, if it has any.
	$context['role_groups'] = array();

	$query = $smcFunc['db_query']('', '
		SELECT id_group
		FROM {db_prefix}helpdesk_role_groups
		WHERE id_role = {int:role}',
		array(
			'role' => $_REQUEST['role'],
		)
	);

	while ($row = $smcFunc['db_fetch_assoc']($query))
		$context['role_groups'][] = $row['id_group'];

	$smcFunc['db_free_result']($query);

	$context['page_title'] = $txt['shd_edit_role'];
	$context['sub_template'] = 'shd_edit_role';
}

/**
 *	Handle saving a user defined role.
 *
 *	@since 1.1
*/
function shd_admin_save_role()
{
	global $context, $txt, $smcFunc, $scripturl;

	// 1. Time for one of our sessions, mistress?
	checkSession();

	// 2. Acting in a role, are we? Is it one we have the script for?
	$_REQUEST['role'] = isset($_REQUEST['role']) ? (int) $_REQUEST['role'] : 0;
	shd_load_role($_REQUEST['role']);

	// Hah, no, you're just an extra, bye.
	if (empty($context['shd_permissions']['user_defined_roles'][$_REQUEST['role']]))
		fatal_lang_error('shd_unknown_role', false);

	// 2b. Oh, we have actually heard of you. That's fine, we'll just refer to you by codename because we're lazy.
	$role = &$context['shd_permissions']['user_defined_roles'][$_REQUEST['role']];

	// 3. Are you the gunman behind the grassy knoll?
	if (isset($_POST['delete']))
	{
		// Oops, bang you're dead.
		$smcFunc['db_query']('', '
			DELETE FROM {db_prefix}helpdesk_roles
			WHERE id_role = {int:role}',
			array(
				'role' => $_REQUEST['role'],
			)
		);

		$smcFunc['db_query']('', '
			DELETE FROM {db_prefix}helpdesk_role_groups
			WHERE id_role = {int:role}',
			array(
				'role' => $_REQUEST['role'],
			)
		);

		$smcFunc['db_query']('', '
			DELETE FROM {db_prefix}helpdesk_role_permissions
			WHERE id_role = {int:role}',
			array(
				'role' => $_REQUEST['role'],
			)
		);

		// Bat out of hell
		redirectexit('action=admin;area=helpdesk_permissions');
	}

	// 4. The unknown actor in a role?
	if (!isset($_POST['rolename']) || $smcFunc['htmltrim']($smcFunc['htmlspecialchars']($_POST['rolename'])) === '')
		fatal_lang_error('shd_no_role_name', false);
	else
		$_POST['rolename'] = strtr($smcFunc['htmlspecialchars']($_POST['rolename']), array("\r" => '', "\n" => '', "\t" => ''));

	// 4. Is the role different to what we thought it was? If so, informer the director, our good friend Mr. Database
	if ($role['name'] != $_POST['rolename'])
	{
		$smcFunc['db_query']('', '
			UPDATE {db_prefix}helpdesk_roles
			SET role_name = {string:rolename}
			WHERE id_role = {int:role}',
			array(
				'role' => $_REQUEST['role'],
				'rolename' => $_POST['rolename'],
			)
		);
	}

	// 5. Tick off what we can and can't do, it all sounds like so much fun.
	$perm_changes = array(
		'add_update' => array(),
		'remove' => array(),
	);

	$assoc = array(
		'allow' => ROLEPERM_ALLOW,
		'disallow' => ROLEPERM_DISALLOW,
		'deny' => ROLEPERM_DENY,
	);

	foreach ($context['shd_permissions']['permission_list'] as $permission => $details)
	{
		list($ownany, $group, $icon) = $details;
		if (empty($icon))
			continue; // this gets rid of the user/staff/admin permission items!

		if ($ownany)
		{
			if (empty($_POST['perm_' . $permission]) || !in_array($_POST['perm_' . $permission], array('allow_any', 'allow_own', 'disallow', 'deny')))
				$_POST['perm_' . $permission] = 'disallow';

			switch ($_POST['perm_' . $permission])
			{
				case 'allow_any':
					// If it's not in the table of perms, or it is but it's not allowed thus far, change it
					if (!isset($role['permissions'][$permission . '_any']) || $role['permissions'][$permission . '_any'] != ROLEPERM_ALLOW)
						$perm_changes['add_update'][$permission . '_any'] = ROLEPERM_ALLOW;
					$perm_changes['remove'][] = $permission . '_own';
					break;
				case 'allow_own':
					// If it's not in the table of perms, or it is but it's not allowed thus far, change it
					if (!isset($role['permissions'][$permission . '_own']) || $role['permissions'][$permission . '_own'] != ROLEPERM_ALLOW)
						$perm_changes['add_update'][$permission . '_own'] = ROLEPERM_ALLOW;

					// This is where it gets interesting. If the template allows _any, but user wants _own, ensure that is duly noted
					if (!empty($context['shd_permissions']['roles'][$role['template']]['permissions'][$permission . '_any']) && $context['shd_permissions']['roles'][$role['template']]['permissions'][$permission . '_any'] == ROLEPERM_ALLOW)
						$perm_changes['add_update'][$permission . '_any'] = ROLEPERM_DISALLOW;
					else
						$perm_changes['remove'][] = $permission . '_any';
					break;
				case 'disallow':
					// If it doesn't exist in the table, it's a non issue, if it does exit and it's different it needs changing
					if (isset($role['permissions'][$permission . '_any']) && $role['permissions'][$permission . '_any'] != ROLEPERM_DISALLOW)
						$perm_changes['add_update'][$permission . '_any'] = ROLEPERM_DISALLOW;
					if (isset($role['permissions'][$permission . '_own']) && $role['permissions'][$permission . '_own'] != ROLEPERM_DISALLOW)
						$perm_changes['add_update'][$permission . '_own'] = ROLEPERM_DISALLOW;

					break;
				case 'deny': // we're denying the permission as a whole, block it out on both levels
					$perm_changes['add_update'] += array(
						($permission . '_any') => ROLEPERM_DENY,
						($permission . '_own') => ROLEPERM_DENY,
					);
					break;
			}
		}
		else
		{
			if (empty($_POST['perm_' . $permission]) || !in_array($_POST['perm_' . $permission], array('allow', 'disallow', 'deny')))
				$_POST['perm_' . $permission] = 'disallow';

			if ((!isset($role['permissions'][$permission]) && $assoc[$_POST['perm_' . $permission]] != ROLEPERM_DISALLOW) || (isset($role['permissions'][$permission]) && $role['permissions'][$permission] != $assoc[$_POST['perm_' . $permission]])) // it's not actually set, so it's new, or it's different to what we had before
				$perm_changes['add_update'][$permission] = $assoc[$_POST['perm_' . $permission]];
		}
	}

	// 6b. Rack 'em up for the database
	if (!empty($perm_changes['add_update']))
	{
		$insert = array();
		foreach ($perm_changes['add_update'] as $perm => $permvalue)
			$insert[] = array($_REQUEST['role'], $perm, $permvalue);

		$smcFunc['db_insert']('replace',
			'{db_prefix}helpdesk_role_permissions',
			array(
				'id_role' => 'int', 'permission' => 'string', 'add_type' => 'int',
			),
			$insert,
			array(
				'id_role', 'permission',
			)
		);
	}

	if (!empty($perm_changes['remove']))
	{
		$smcFunc['db_query']('', '
			DELETE FROM {db_prefix}helpdesk_role_permissions
			WHERE id_role = {int:role}
				AND permission IN ({array_string:permissions})
			LIMIT 1',
			array(
				'role' => $_REQUEST['role'],
				'permissions' => $perm_changes['remove'],
			)
		);

	}

	// 7. (serious voice) OK let's do groups. Grab the ones that are valid groups in SMF, ignore everything else
	// We're not interested in admin (group 1), board mod (group 3) or post count groups (min_posts != -1)
	$context['membergroups'] = array(0);

	$query = $smcFunc['db_query']('', '
		SELECT mg.id_group
		FROM {db_prefix}membergroups AS mg
		WHERE mg.min_posts = -1
			AND mg.id_group NOT IN (1,3)
		ORDER BY id_group',
		array()
	);

	while ($row = $smcFunc['db_fetch_assoc']($query))
		$context['membergroups'][] = $row['id_group'];

	$groups = array(
		'add' => array(),
		'remove' => array(),
	);

	foreach ($context['membergroups'] as $group)
	{
		if (!empty($_POST['group' . $group]))
		{
			if (empty($role['groups'][$group])) // box is ticked but it's one we don't know about already
				$groups['add'][] = $group;
		}
		else
		{
			if (!empty($role['groups'][$group])) // box is empty but it's one that was attached to this role
				$groups['remove'][] = $group;
		}
	}

	if (!empty($groups['remove']))
	{
		$smcFunc['db_query']('', '
			DELETE FROM {db_prefix}helpdesk_role_groups
			WHERE id_role = {int:role}
				AND id_group IN ({array_int:groups})',
			array(
				'role' => $_REQUEST['role'],
				'groups' => $groups['remove'],
			)
		);
	}

	if (!empty($groups['add']))
	{
		$insert = array();
		foreach ($groups['add'] as $add)
			$insert[] = array($_REQUEST['role'], $add);

		$smcFunc['db_insert']('replace',
			'{db_prefix}helpdesk_role_groups',
			array(
				'id_role' => 'int', 'id_group' => 'int',
			),
			$insert,
			array(
				'id_role', 'id_group',
			)
		);
	}

	// All done, back to the main screen
	redirectexit('action=admin;area=helpdesk_permissions');
}

/**
 *	Handles user requests to copy an existing role.
 *
 *	@since 1.1
*/
function shd_admin_copy_role()
{
	global $context, $txt, $smcFunc;

	$_REQUEST['role'] = isset($_REQUEST['role']) ? (int) $_REQUEST['role'] : 0;
	shd_load_role($_REQUEST['role']);

	// Hah, no, you're just an extra, bye.
	if (empty($context['shd_permissions']['user_defined_roles'][$_REQUEST['role']]))
		fatal_lang_error('shd_unknown_role', false);

	if (empty($_REQUEST['part']))
	{
		$context['page_title'] = $txt['shd_copy_role'];
		$context['sub_template'] = 'shd_copy_role';
		checkSubmitOnce('register');
	}
	else
	{
		checkSubmitOnce('check');
		checkSession();

		// Boring stuff like session checks done. Were you a naughty admin and didn't set it properly?
		if (!isset($_POST['rolename']) || $smcFunc['htmltrim']($smcFunc['htmlspecialchars']($_POST['rolename'])) === '')
			fatal_lang_error('shd_no_role_name', false);
		else
			$_POST['rolename'] = strtr($smcFunc['htmlspecialchars']($_POST['rolename']), array("\r" => '', "\n" => '', "\t" => ''));

		// So here we are, source role is valid, we're good little admins and specified a name, so let's create the new role in the DB.
		$smcFunc['db_insert']('insert',
			'{db_prefix}helpdesk_roles',
			array(
				'template' => 'int', 'role_name' => 'string',
			),
			array(
				$context['shd_permissions']['user_defined_roles'][$_REQUEST['role']]['template'], $_POST['rolename'],
			),
			array(
				'id_role',
			)
		);

		$newrole = $smcFunc['db_insert_id']('{db_prefix}helpdesk_roles', 'id_role');
		if (empty($newrole))
			fatal_lang_error('shd_could_not_create_role', false);

		// OK, so we made the role. Now add the permissions from the existing role, first grab 'em
		$new_perms = array();
		$query = $smcFunc['db_query']('', '
			SELECT permission, add_type
			FROM {db_prefix}helpdesk_role_permissions
			WHERE id_role = {int:role}',
			array(
				'role' => $_REQUEST['role'],
			)
		);

		while ($row = $smcFunc['db_fetch_assoc']($query))
			$new_perms[] = array((int) $newrole, $row['permission'], (int) $row['add_type']);

		$smcFunc['db_free_result']($query);

		// Now insert them new perms if they got any
		if (!empty($new_perms))
		{
			$smcFunc['db_insert']('insert',
				'{db_prefix}helpdesk_role_permissions',
				array(
					'id_role' => 'int', 'permission' => 'string', 'add_type' => 'int',
				),
				$new_perms,
				array(
					'id_role', 'permission',
				)
			);
		}

		// Now copy the groups if they wanted to
		if (!empty($_REQUEST['copygroups']))
		{
			$groups = array();
			$query = $smcFunc['db_query']('', '
				SELECT id_group
				FROM {db_prefix}helpdesk_role_groups
				WHERE id_role = {int:role}',
				array(
					'role' => $_REQUEST['role'],
				)
			);

			while ($row = $smcFunc['db_fetch_assoc']($query))
				$groups = array((int) $newrole, (int) $row['id_group']);

			$smcFunc['db_free_result']($query);

			if (!empty($groups))
			{
				$smcFunc['db_insert']('insert',
					'{db_prefix}helpdesk_role_groups',
					array(
						'id_role' => 'int', 'id_group' => 'int',
					),
					$groups,
					array(
						'id_role', 'id_group',
					)
				);
			}
		}

		// Take them to the edit screen!
		redirectexit('action=admin;area=helpdesk_permissions;sa=editrole;role=' . $newrole);
	}
}

/**
 *	Loads user defined roles.
 *
 *	@param int $loadrole Specifies the role to load from the database. If not specified, loads all known roles.
 *
 *	@since 1.1
*/
function shd_load_role($loadrole = 0)
{
	global $context, $smcFunc, $scripturl, $txt;
	$loadrole = (int) $loadrole; // just in case

	$query = $smcFunc['db_query']('', '
		SELECT id_role, template, role_name
		FROM {db_prefix}helpdesk_roles' . (empty($loadrole) ? '' : '
		WHERE id_role = {int:role}') . '
		ORDER BY id_role',
		array(
			'role' => $loadrole,
		)
	);

	while ($row = $smcFunc['db_fetch_assoc']($query))
	{
		$context['shd_permissions']['user_defined_roles'][$row['id_role']] = array(
			'template' => $row['template'],
			'name' => $row['role_name'],
		);
	}
	$smcFunc['db_free_result']($query);

	// OK, are we done already?
	if (empty($context['shd_permissions']['user_defined_roles']) || (!empty($loadrole) && empty($context['shd_permissions']['user_defined_roles'][$loadrole])))
		return;

	// Guess not. Now load the roles that are attached to the group(s)
	$query = $smcFunc['db_query']('', '
		SELECT hdrg.id_role, hdrg.id_group, mg.group_name, mg.online_color
		FROM {db_prefix}helpdesk_role_groups AS hdrg
			LEFT JOIN {db_prefix}membergroups AS mg ON (hdrg.id_group = mg.id_group)' . (empty($loadrole) ? '' : '
		WHERE id_role = {int:role}') . '
		ORDER BY hdrg.id_role, hdrg.id_group',
		array(
			'role' => $loadrole,
		)
	);

	while ($row = $smcFunc['db_fetch_assoc']($query))
	{
		// WHAT? You're letting REGULAR MEMBERS in?! LIEK 4 REALZ? *shrug* Come on in! :P
		if (empty($row['id_group']))
		{
			$row['id_group'] = 0;
			$row['group_name'] = $txt['membergroups_members'];
			$row['color'] = '';
		}

		$context['shd_permissions']['user_defined_roles'][$row['id_role']]['groups'][$row['id_group']] = array(
			'name' => $row['group_name'],
			'color' => $row['online_color'],
			'link' => empty($row['id_group']) ? $row['group_name'] : '<a href="' . $scripturl . '?action=groups;sa=members;group=' . $row['id_group'] . '"' . (empty($row['online_color']) ? '' : ' style="color: ' . $row['online_color'] . ';"') . '>' . $row['group_name'] . '</a>',
		);
	}
	$smcFunc['db_free_result']($query);

	// OK, lastly fire up the permissions!
	// 1. Load the templates
	foreach ($context['shd_permissions']['user_defined_roles'] as $role => $role_details)
	{
		$template = &$context['shd_permissions']['roles'][$role_details['template']];
		$context['shd_permissions']['user_defined_roles'][$role] += array(
			'permissions' => $template['permissions'],
			'template_icon' => $template['icon'],
			'template_name' => $txt[$template['description']],
		);
	}

	// 2. Apply the changes from the table to the template(s) forms
	$query = $smcFunc['db_query']('', '
		SELECT id_role, permission, add_type
		FROM {db_prefix}helpdesk_role_permissions' . (empty($loadrole) ? '' : '
		WHERE id_role = {int:role}'),
		array(
			'role' => $loadrole,
		)
	);

	while($row = $smcFunc['db_fetch_assoc']($query))
		$context['shd_permissions']['user_defined_roles'][$row['id_role']]['permissions'][$row['permission']] = $row['add_type']; // if it's defined in the DB it's somehow different to what the template so replace the template

	$smcFunc['db_free_result']($query);
}

?>