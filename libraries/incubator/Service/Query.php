<?php
/**
 * @package     Joomla.Framework
 * @subpackage  Service Layer
 *
 * @copyright   Copyright (C) 2005 - 2016 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Joomla\Service;

/**
 * Abstract base class for immutable queries.
 *
 * Queries are immutable objects that are completely defined by the arguments
 * passed to them in their constructors.  Query argument validation logic
 * may be added in the constructor.  Some basic checks are performed to try to
 * enforce immutability, but these only really guard against accidental
 * alteration of object state.
 *
 * @package  Joomla/Service
 *
 * @since    __DEPLOY_VERSION__
 */
abstract class Query extends Message
{
}
