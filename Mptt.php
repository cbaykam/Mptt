<?php
require_once(APP_ROOT . 'public/v1/helpers/MySQLiHelper.php');
class Mptt
{

    public $table_name; 
    public $title_column;
    public $connection; 
    /**
     *  Constructor of the class
     *  @param {string} $table_name  MySQL table name to be used for storing items.
     *  @param {string} $id_column   (Optional) Name of the column that 
     *                                uniquely identifies items in the table, Default is <i>id</i>
     *  @param {string} $title_column (Optional) Name of the column that stores item names, Default is <i>title</i>
     *  @param {string} $left_column (Optional) Name of the column that stores "left" values
     *                                Default is <i>lft</i> ("left" is a reserved word in MySQL)
     *  @param {string} $right_column (Optional) Name of the column that stores "right" values
     *                                 Default is <i>rght</i> ("right" is a reserved word in MySQL)
     *  @param {string} $parent_column (Optional) Name of the column that stores the IDs of parent items
     *                                  Default is <i>parent</i>
     *  @return void
     */
    public function __construct($init_connection = true, $id_column = 'id', $left_column = 'lft', $right_column = 'rght', $parent_column = 'parent_id') {

        if($init_connection){
            $this->connection = new MySQLiHelper();
        }

        // initialize properties
        $this->properties = array(
            'table_name'    =>  $this->table_name,
            'id_column'     =>  $id_column,
            'title_column'  =>  $this->title_column,
            'left_column'   =>  $left_column,
            'right_column'  =>  $right_column,
            'parent_column' =>  $parent_column,
        );

    }

    function add($parent, $fields, $tree_id = null, $position = false) {
        // lazy connection: touch the database only when the data is required for the first time and not at object instantiation
        $this->_init($tree_id);

        // make sure parent ID is an integer
        $parent = (int)$parent;

        // continue only if
        if ( $parent == 0 || isset($this->lookup[$parent]) ) {
            // get parent's children nodes (no deeper than the first level)
            $children = $this->get_children($parent, $tree_id, true);

            // if node is to be inserted in the default position (as the last of the parent node's children)
            if ($position === false){
                // give a numerical value to the position
                $position = count($children);
            // if a custom position was specified
            }else {

                // make sure that position is an integer value
                $position = (int)$position;

                // if position is a bogus number
                if ($position > count($children) || $position < 0)

                    // use the default position (as the last of the parent node's children)
                    $position = count($children);

            }

            // if parent has no children OR the node is to be inserted as the parent node's first child
            if (empty($children) || $position == 0)

                // set the boundary - nodes having their "left"/"right" values outside this boundary will be affected by
                // the insert, and will need to be updated
                // if parent is not found (meaning that we're inserting a topmost node) set the boundary to 0
                $boundary = isset($this->lookup[$parent]) ? $this->lookup[$parent][$this->properties['left_column']] : 0;

            // if parent node has children nodes and/or the node needs to be inserted at a specific position
            else {

                // find the child node that currently exists at the position where the new node needs to be inserted to
                $slice = array_slice($children, $position - 1, 1);

                $children = array_shift($slice);

                // set the boundary - nodes having their "left"/"right" values outside this boundary will be affected by
                // the insert, and will need to be updated
                $boundary = $children[$this->properties['right_column']];

            }

            // iterate through all the records in the lookup array
            foreach ($this->lookup as $id => $properties) {

                // if the node's "left" value is outside the boundary
                if ($properties[$this->properties['left_column']] > $boundary)

                    // increment it with 2
                    $this->lookup[$id][$this->properties['left_column']] += 2;

                // if the node's "right" value is outside the boundary
                if ($properties[$this->properties['right_column']] > $boundary)

                    // increment it with 2
                    $this->lookup[$id][$this->properties['right_column']] += 2;

            }

            // lock table to prevent other sessions from modifying the data and thus preserving data integrity
            $this->connection->sql('LOCK TABLE ' . $this->properties['table_name'] . ' WRITE');

            // update the nodes in the database having their "left"/"right" values outside the boundary
            $arrange_left_qr = '

                UPDATE
                    ' . $this->properties['table_name'] . '
                SET
                    ' . $this->properties['left_column'] . ' = ' . $this->properties['left_column'] . ' + 2
                WHERE
                    ' . $this->properties['left_column'] . ' > ' . $boundary . '

            '; 

            $arrange_right_qr = '

                UPDATE
                    ' . $this->properties['table_name'] . '
                SET
                    ' . $this->properties['right_column'] . ' = ' . $this->properties['right_column'] . ' + 2
                WHERE
                    ' . $this->properties['right_column'] . ' > ' . $boundary . '

            '; 

            if($tree_id){
                $arrange_left_qr .= " AND tree_id = " . $tree_id; 
                $arrange_right_qr .= " AND tree_id = " . $tree_id; 
            }

            $this->connection->query($arrange_left_qr);
            $this->connection->query($arrange_right_qr);
            
            $fields[$this->properties['title_column']] = $this->connection->escape($fields[$this->title_column]);
            $fields[$this->properties['left_column']] = ($boundary + 1);
            $fields[$this->properties['right_column']] = ($boundary + 2);
            $fields[$this->properties['parent_column']] = $parent; 
            $qr = $this->shape_query($fields);
            // insert the new node into the database
            $this->connection->query($qr);
            
            // get the ID of the newly inserted node
            $node_id = $this->connection->insertID();

            // release table lock
            $this->connection->sql('UNLOCK TABLES');

            // add the node to the lookup array
            $this->lookup[$node_id] = array(
                $this->properties['id_column']      => $node_id,
                $this->properties['title_column']   => $fields[$this->title_column],
                $this->properties['left_column']    => $boundary + 1,
                $this->properties['right_column']   => $boundary + 2,
                $this->properties['parent_column']  => $parent,
            );

            // reorder the lookup array
            $this->_reorder_lookup_array();

            // return the ID of the newly inserted node
            return $node_id;

        }

        // if script gets this far, something must've went wrong so we return false
        return false;

    }

    function copy($source, $target, $tree_id = null, $position = false) {
    
        // lazy connection: touch the database only when the data is required for the first time and not at object instantiation
        $this->_init($tree_id);

        // continue only if
        if (

            // source node exists in the lookup array AND
            isset($this->lookup[$source]) &&

            // target node exists in the lookup array OR is 0 (indicating a topmost node)
            (isset($this->lookup[$target]) || $target == 0)

        ) {

            // get the source's children nodes (if any)
            $source_children = $this->get_children($source, $tree_id);

            // this array will hold the items we need to copy
            // by default we add the source item to it
            $sources = array($this->lookup[$source]);

            // the copy's parent will be the target node
            $sources[0][$this->properties['parent_column']] = $target;

            // iterate through source node's children
            foreach ($source_children as $child)

                // save them for later use
                $sources[] = $this->lookup[$child[$this->properties['id_column']]];

            // the value with which items outside the boundary set below, are to be updated with
            $source_rl_difference =

                $this->lookup[$source][$this->properties['right_column']] -

                $this->lookup[$source][$this->properties['left_column']]

                + 1;

            // set the boundary - nodes having their "left"/"right" values outside this boundary will be affected by
            // the insert, and will need to be updated
            $source_boundary = $this->lookup[$source][$this->properties['left_column']];

            // get target node's children (no deeper than the first level)
            $target_children = $this->get_children($target, $tree_id, true);

            // if copy is to be inserted in the default position (as the last of the target node's children)
            if ($position === false)

                // give a numerical value to the position
                $position = count($target_children);

            // if a custom position was specified
            else {

                // make sure given position is an integer value
                $position = (int)$position;

                // if position is a bogus number
                if ($position > count($target_children) || $position < 0)

                    // use the default position (the last of the target node's children)
                    $position = count($target_children);

            }

            // we are about to do an insert and some nodes need to be updated first

            // if target has no children nodes OR the copy is to be inserted as the target node's first child node
            if (empty($target_children) || $position == 0)

                // set the boundary - nodes having their "left"/"right" values outside this boundary will be affected by
                // the insert, and will need to be updated
                // if parent is not found (meaning that we're inserting a topmost node) set the boundary to 0
                $target_boundary = isset($this->lookup[$target]) ? $this->lookup[$target][$this->properties['left_column']] : 0;

            // if target has children nodes and/or the copy needs to be inserted at a specific position
            else {

                // find the target's child node that currently exists at the position where the new node needs to be inserted to
                $slice = array_slice($target_children, $position - 1, 1);

                $target_children = array_shift($slice);

                // set the boundary - nodes having their "left"/"right" values outside this boundary will be affected by
                // the insert, and will need to be updated
                $target_boundary = $target_children[$this->properties['right_column']];

            }

            // iterate through the nodes in the lookup array
            foreach ($this->lookup as $id => $properties) {

                // if the "left" value of node is outside the boundary
                if ($properties[$this->properties['left_column']] > $target_boundary)

                    // increment it
                    $this->lookup[$id][$this->properties['left_column']] += $source_rl_difference;

                // if the "right" value of node is outside the boundary
                if ($properties[$this->properties['right_column']] > $target_boundary)

                    // increment it
                    $this->lookup[$id][$this->properties['right_column']] += $source_rl_difference;

            }

            // lock table to prevent other sessions from modifying the data and thus preserving data integrity
            $this->connection->sql('LOCK TABLE ' . $this->properties['table_name'] . ' WRITE');


            $arrange_right_qr = '

                UPDATE
                    ' . $this->properties['table_name'] . '
                SET
                    ' . $this->properties['right_column'] . ' = ' . $this->properties['right_column'] . ' + ' . $source_rl_difference . '
                WHERE
                    ' . $this->properties['right_column'] . ' > ' . $target_boundary . '

            ';

            $arrange_left_qr = '

                UPDATE
                    ' . $this->properties['table_name'] . '
                SET
                    ' . $this->properties['left_column'] . ' = ' . $this->properties['left_column'] . ' + ' . $source_rl_difference . '
                WHERE
                    ' . $this->properties['left_column'] . ' > ' . $target_boundary . '

            '; 

            if($tree_id){ 
                $arrange_left_qr .= " AND tree_id = "  . $tree_id ;
                $arrange_right_qr .= " AND tree_id = " . $tree_id ; 
            }
            // update the nodes in the database having their "left"/"right" values outside the boundary
            $this->connection->query($arrange_left_qr);

            $this->connection->query($arrange_right_qr);

            // finally, the nodes that are to be inserted need to have their "left" and "right" values updated
            $shift = $target_boundary - $source_boundary + 1;

            // iterate through the nodes that are to be inserted
            foreach ($sources as $id => &$properties) {

                // update "left" value
                $properties[$this->properties['left_column']] += $shift;

                // update "right" value
                $properties[$this->properties['right_column']] += $shift;

                // insert into the database
                $this->connection->query('
                    INSERT INTO
                        ' . $this->properties['table_name'] . '
                        (
                            ' . $this->properties['title_column'] . ',
                            ' . $this->properties['left_column'] . ',
                            ' . $this->properties['right_column'] . ',
                            ' . $this->properties['parent_column'] . '
                        )
                    VALUES
                        (
                            "' . mysql_real_escape_string($properties[$this->properties['title_column']]) . '",
                            ' . $properties[$this->properties['left_column']] . ',
                            ' . $properties[$this->properties['right_column']] . ',
                            ' . $properties[$this->properties['parent_column']] . '
                        )
                ');

                // get the ID of the newly inserted node
                $node_id = $this->connection->insertID();

                // because the node may have children nodes and its ID just changed
                // we need to find its children and update the reference to the parent ID
                foreach ($sources as $key => $value)

                    // if a child node was found
                    if ($value[$this->properties['parent_column']] == $properties[$this->properties['id_column']])

                        // update the reference to the parent ID
                        $sources[$key][$this->properties['parent_column']] = $node_id;

                // update the node's properties with the ID
                $properties[$this->properties['id_column']] = $node_id;

                // update the array of inserted items
                $sources[$id] = $properties;

            }

            // a reference of a $properties and the last array element remain even after the foreach loop
            // we have to destroy it
            unset($properties);

            // release table lock
            $this->connection->sql('UNLOCK TABLES');

            // at this point, we have the nodes in the database but we need to also update the lookup array

            $parents = array();

            // iterate through the inserted nodes
            foreach ($sources as $id => $properties) {

                // if the node has any parents
                if (count($parents) > 0)

                    // iterate through the array of parent nodes
                    while ($parents[count($parents) - 1]['right'] < $properties[$this->properties['right_column']])

                        // and remove those which are not parents of the current node
                        array_pop($parents);

                // if there are any parents left
                if (count($parents) > 0)

                    // the last node in the $parents array is the current node's parent
                    $properties[$this->properties['parent_column']] = $parents[count($parents) - 1]['id'];

                // update the lookup array
                $this->lookup[$properties[$this->properties['id_column']]] = $properties;

                // add current node to the stack
                $parents[] = array(

                    'id'    =>  $properties[$this->properties['id_column']],
                    'right' =>  $properties[$this->properties['right_column']]

                );

            }

            // reorder the lookup array
            $this->_reorder_lookup_array();

            // return the ID of the copy
            return $sources[0][$this->properties['id_column']];

        }

        // if scripts gets this far, return false as something must've went wrong
        return false;

    }

    /**
     *  Deletes a node, including the node's children nodes.
     *
     *  <code>
     *  // add a topmost node
     *  $node = $mptt->add(0, 'Main');
     *
     *  // add child node
     *  $child1 = $mptt->add($node, 'Child 1');
     *
     *  // add another child node
     *  $child2 = $mptt->add($node, 'Child 2');
     *
     *  // delete the "Child 2" node
     *  $mptt->delete($child2);
     *  </code>
     *
     *  @param  integer     $node       The ID of the node to delete.
     *
     *  @return boolean                 TRUE on success or FALSE upon error.
     */
    function delete($node, $tree_id = null) { 
        // lazy connection: touch the database only when the data is required for the first time and not at object instantiation
        $this->_init($tree_id);

        // continue only if target node exists in the lookup array
        if (isset($this->lookup[$node])) {

            // get target node's children nodes (if any)
            $children = $this->get_children($node, $tree_id);

            // iterate through target node's children nodes
            foreach ($children as $child)

                // remove node from the lookup array
                unset($this->lookup[$child[$this->properties['id_column']]]);

            // lock table to prevent other sessions from modifying the data and thus preserving data integrity
            $this->connection->sql('LOCK TABLE ' . $this->properties['table_name'] . ' WRITE');

            // also remove nodes from the database
            $delete_qr = '

                DELETE
                FROM
                    ' . $this->properties['table_name'] . '
                WHERE
                    ' . $this->properties['left_column'] . ' >= ' . $this->lookup[$node][$this->properties['left_column']] . ' AND
                    ' . $this->properties['right_column'] . ' <= ' . $this->lookup[$node][$this->properties['right_column']] . '

            ';

            if($tree_id){
                $delete_qr .= " AND tree_id = " . $tree_id ;  
            }

            $this->connection->query($delete_qr);

            // the value with which items outside the boundary set below, are to be updated with
            $target_rl_difference =

                $this->lookup[$node][$this->properties['right_column']] -

                $this->lookup[$node][$this->properties['left_column']]

                + 1;

            // set the boundary - nodes having their "left"/"right" values outside this boundary will be affected by
            // the insert, and will need to be updated
            $boundary = $this->lookup[$node][$this->properties['left_column']];

            // remove the target node from the lookup array
            unset($this->lookup[$node]);

            // iterate through nodes in the lookup array
            foreach ($this->lookup as $id => $properties) {

                // if the "left" value of node is outside the boundary
                if ($this->lookup[$id][$this->properties['left_column']] > $boundary)

                    // decrement it
                    $this->lookup[$id][$this->properties['left_column']] -= $target_rl_difference;

                // if the "right" value of node is outside the boundary
                if ($this->lookup[$id][$this->properties['right_column']] > $boundary)

                    // decrement it
                    $this->lookup[$id][$this->properties['right_column']] -= $target_rl_difference;

            }

            $arrange_left_qr = '

                UPDATE
                    ' . $this->properties['table_name'] . '
                SET
                    ' . $this->properties['left_column'] . ' = ' . $this->properties['left_column'] . ' - ' . $target_rl_difference . '
                WHERE
                    ' . $this->properties['left_column'] . ' > ' . $boundary . '

            ';

            $arrange_right_qr = '

                UPDATE
                    ' . $this->properties['table_name'] . '
                SET
                    ' . $this->properties['right_column'] . ' = ' . $this->properties['right_column'] . ' - ' . $target_rl_difference . '
                WHERE
                    ' . $this->properties['right_column'] . ' > ' . $boundary . '

            ';

            if($tree_id){
                $arrange_left_qr .= " AND tree_id = " . $tree_id ; 
                $arrange_right_qr .= " AND tree_id = " . $tree_id ; 
            }

            // update the nodes in the database having their "left"/"right" values outside the boundary
            $this->connection->query($arrange_left_qr);

            $this->connection->query($arrange_right_qr);

            // release table lock
            $this->connection->sql('UNLOCK TABLES');

            // return true as everything went well
            return true;

        }

        // if script gets this far, something must've went wrong so we return false
        return false;

    }

    /**
     *  Returns an unidimensional (flat) array with the children nodes of a given parent node.
     *
     *  <i>For a multi-dimensional array use the {@link get_tree()} method.</i>
     *
     *  @param  integer     $parent             (Optional) The ID of the node for which to return children nodes.
     *
     *                                          Default is "0" - return all the nodes.
     *
     *  @param  boolean     $children_only      (Optional) Set this to TRUE to return only the node's direct children nodes
     *                                          (and no children nodes of children nodes of children nodes...)
     *
     *                                          Default is FALSE
     *
     *  @return array                           Returns an unidimensional array with the children nodes of the given
     *                                          parent node.
     */
    function get_children($parent = 0, $tree_id = null, $children_only = false, $init_again = false) {

        // lazy connection: touch the database only when the data is required for the first time and not at object instantiation
        $this->_init($tree_id, $init_again);

        // if parent node exists in the lookup array OR we're looking for the topmost nodes
        if (isset($this->lookup[$parent]) || $parent === 0) {

            $children = array();

            // get the keys in the lookup array
            $keys = array_keys($this->lookup);

            // iterate through the available keys
            foreach ($keys as $item)

                // if
                if (

                    // node's "left" is higher than parent node's "left" (or, if parent is 0, if it is higher than 0)
                    $this->lookup[$item][$this->properties['left_column']] > ($parent !== 0 ? $this->lookup[$parent][$this->properties['left_column']] : 0) &&

                    // node's "left" is smaller than parent node's "right" (or, if parent is 0, if it is smaller than PHP's maximum integer value)
                    $this->lookup[$item][$this->properties['left_column']] < ($parent !== 0 ? $this->lookup[$parent][$this->properties['right_column']] : PHP_INT_MAX) &&

                    // if we only need the first level children, check if children node's parent node is the parent given as argument
                    (!$children_only || ($children_only && $this->lookup[$item][$this->properties['parent_column']] == $parent))

                )

                    // save to array
                    $children[$this->lookup[$item][$this->properties['id_column']]] = $this->lookup[$item];

            // return children nodes
            return $children;

        }

        // if script gets this far, return false as something must've went wrong
        return false;

    }

    /**
     *  Returns the number of direct children nodes that a given node has (excluding children nodes of children nodes of
     *  children nodes and so on)
     *
     *  @param  integer     $node               The ID of the node for which to return the number of direct children nodes.
     *
     *  @return integer                         Returns the number of direct children nodes that a given node has, or
     *                                          FALSE on error.
     *
     *                                          <i>Since this method may return both "0" and FALSE, make sure you use ===
     *                                          to verify the returned result!</i>
     */
    function get_children_count($node, $tree_id = null) {

        // lazy connection: touch the database only when the data is required for the first time and not at object instantiation
        $this->_init($tree_id);

        // if node exists in the lookup array
        if (isset($this->lookup[$node])) {

            $result = 0;

            // iterate through all the records in the lookup array
            foreach ($this->lookup as $id => $properties)

                // if node is a direct children of the parent node
                if ($this->lookup[$id][$this->properties['parent_column']] == $node)

                    // increment the number of direct children
                    $result++;

            // return the number of direct children nodes
            return $result;

        }

        // if script gets this far, return false as something must've went wrong
        return false;

    }

    /**
     *  Returns the number of total children nodes that a given node has, including children nodes of children nodes of
     *  children nodes and so on.
     *
     *  @param  integer     $node               The ID of the node for which to return the total number of descendant nodes.
     *
     *  @return integer                         Returns the number of total children nodes that a given node has, or
     *                                          FALSE on error.
     *
     *                                          <i>Since this method may return both "0" and FALSE, make sure you use ===
     *                                          to verify the returned result!</i>
     */
    function get_descendants_count($node, $tree_id = null) {

        // lazy connection: touch the database only when the data is required for the first time and not at object instantiation
        $this->_init($tree_id);

        // if parent node exists in the lookup array
        if (isset($this->lookup[$node]))

            // return the total number of descendant nodes
            return ($this->lookup[$node][$this->properties['right_column']] - $this->lookup[$node][$this->properties['left_column']] - 1) / 2;

        // if script gets this far, return false as something must've went wrong
        return false;

    }

    /**
     *  Returns information about the node's direct parent node.
     *
     *  If node given as argument has a direct parent node, returns an array containing the parent node's properties. If
     *  node given as argument is a topmost node, returns 0.
     *
     *  @param  integer     $node               The ID of a node for which to return the direct parent node's properties.
     *
     *  @return mixed                           If node given as argument has a direct parent node, returns an array
     *                                          containing the parent node's properties. If node given as argument is a
     *                                          topmost node, returns 0.
     *
     *                                          <i>Since this method may return both "0" and FALSE, make sure you use ===
     *                                          to verify the returned result!</>
     */
    function get_parent($node, $tree_id = null) {

        // lazy connection: touch the database only when the data is required for the first time and not at object instantiation
        $this->_init($tree_id);

        // if node exists in the lookup array
        if (isset($this->lookup[$node]))

            // if node has a parent node, return the parent node's properties
            // also, return 0 if the node is a topmost node
            return isset($this->lookup[$this->lookup[$node][$this->properties['parent_column']]]) ? $this->lookup[$this->lookup[$node][$this->properties['parent_column']]] : 0;

        // if script gets this far, return false as something must've went wrong
        return false;

    }

    /**
     *  Returns an unidimensional (flat) array with the path to the given node (including the node itself).
     *
     *  @param  integer     $node               The ID of a node for which to return the path.
     *
     *  @return array                           Returns an unidimensional array with the path to the given node.
     */
    function get_path($node, $tree_id = null) {

        // lazy connection: touch the database only when the data is required for the first time and not at object instantiation
        $this->_init($tree_id);

        $parents = array();

        // if node exists in the lookup array
        if (isset($this->lookup[$node])) {

            // iterate through all the nodes in the lookup array
            foreach ($this->lookup as $id => $properties)

                // if
                if (

                    // node is a parent node
                    $properties[$this->properties['left_column']] < $this->lookup[$node][$this->properties['left_column']] &&

                    $properties[$this->properties['right_column']] > $this->lookup[$node][$this->properties['right_column']]

                )

                    // save the parent node's information
                    $parents[$properties[$this->properties['id_column']]] = $properties;

        }

        // add also the node given as argument
        $parents[$node] = $this->lookup[$node];

        // return the path to the node
        return $parents;

    }

    /**
     *  Returns an array of children nodes of a node given as argument, indented and ready to be used in a <select>
     *  control.
     *
     *  <code>
     *  $selectables = $mptt->get_selectables($node_id);
     *
     *  echo '<select name="myselect">';
     *
     *  foreach ($selectables as $value => $caption)
     *
     *      echo '<option value="' . $value . '">' . $caption . '</option>';
     *
     *  echo '</select>';
     *  </code>
     *
     *  @param  integer     $node       (Optional) The ID of a node for which to fetch its children nodes and return
     *                                  the node and its children as an array, indented and ready to be used in a <select>
     *                                  control.
     *
     *                                  Default is "0" - the generated array contains *all* the available nodes.
     *
     *  @param  string      $separator  A string to indent the nodes by.
     *
     *                                  Default is " &rarr; "
     *
     *  @return array                   Returns an array of children nodes of a node given as argument, indented and ready
     *                                  to be used in a <select> control.
     */
    function get_selectables($node = 0, $tree_id = null, $separator = ' &rarr; ') {
    
        // lazy connection: touch the database only when the data is required for the first time and not at object instantiation
        $this->_init($tree_id);

        // continue only if
        if (

            // parent node exists in the lookup array OR is 0 (indicating topmost node)
            isset($this->lookup[$node]) || $node == 0

        ) {

            // the resulting array and a temporary array
            $result = $parents = array();

            // get node's children nodes
            $children = $this->get_children($node, $tree_id);
            
            // if node is not 0
            if ($node != 0)

                // prepend the item itself to the list
                array_unshift($children, $this->lookup[$node]);
                
            // iterate through the nodes
            foreach ($children as $id => $properties) {

                // if we find a topmost node
                if ($properties[$this->properties['parent_column']] == 0) {

                    // if the $categories variable is set, save the categories we have so far
                    if (isset($nodes)) $result += $nodes;

                    // reset the categories and parents arrays
                    $nodes = $parents = array();

                }

                // if the node has any parents
                if (count($parents) > 0) {

                    $keys = array_keys($parents);

                    // iterate through the array of parent nodes
                    while (array_pop($keys) < $properties[$this->properties['right_column']])

                        // and remove parents that are not parents of current node
                        array_pop($parents);

                }

                // add node to the stack of nodes
                $nodes[$properties[$this->properties['id_column']]] = (!empty($parents) ? str_repeat($separator, count($parents)) : '') . $properties[$this->properties['title_column']];

                // add node to the stack of parents
                $parents[$properties[$this->properties['right_column']]] = $properties[$this->properties['title_column']];

            }

            // may not be set when there are no nodes at all
            if (isset($nodes))

                // finalize the result
                $result += $nodes;

            // return the resulting array
            return $result;
            
        }
        
        // if the script gets this far, return false as something must've went wrong
        return false;

    }

    /**
     *  Returns a multi dimensional array with all the descendant nodes (including children nodes of children nodes of
     *  children nodes and so on) of a given node.
     *
     *  @param  integer     $node               (Optional) The ID of the node for which to return all descendant nodes
     *                                          as a multi-dimensional array.
     *
     *                                          Default is "0" - return all the nodes.
     *
     *  @return array                           Returns a multi dimensional array with all the descendant nodes (including
     *                                          children nodes of children nodes of children nodes and so on) of a given
     *                                          node.
     */
    function get_tree($node = 0, $tree_id, $children_only = true, $init_again = false) {
        // get direct children nodes
        $result = $this->get_children($node, $tree_id, $children_only, $init_again);

        // iterate through the direct children nodes
        foreach ($result as $id => $properties)

            // for each child node create a "children" property
            // and get the node's children nodes, recursively
            $result[$id]['children'] = $this->get_tree($id, $tree_id);

        // return the array
        return $result;

    }
    
    /**
     * @ TODO make this work. We don't need this at the moment. 
     *  Moves a node, including node's children nodes, as the child of a target node.
     *
     *  <code>
     *  // insert a topmost node
     *  $node = $mptt->add(0, 'Main');
     *
     *  // add a child node
     *  $child1 = $mptt->add($node, 'Child 1');
     *
     *  // add another child node
     *  $child2 = $mptt->add($node, 'Child 2');
     *
     *  // move "Child 2" node to be the first of "Main"'s children nodes
     *  $mptt->move($child2, $node, 0);
     *  </code>
     *
     *  @param  integer     $source     The ID of the node to move.
     *
     *  @param  integer     $target     The ID of the node where {@link $source} node needs to be moved to. Use "0" if
     *                                  the node does not need a parent node (making it a topmost node).
     *
     *  @param  integer     $position   (Optional) The position the node will have amongst the {@link $parent}'s
     *                                  children nodes.
     *
     *                                  When {@link $parent} is "0", this refers to the position the node will have
     *                                  amongst the topmost nodes.
     *
     *                                  The values are 0-based, meaning that if you want the node to be inserted as
     *                                  the first in the list of {@link $parent}'s children nodes, you have to use "0".<br>
     *                                  If you want it to be second use "1", and so on.
     *
     *                                  Default is "0" - the node will be inserted as last of the {@link $parent}'s
     *                                  children nodes.
     *
     *  @return boolean                 TRUE on success or FALSE upon error
     */
    function move($source, $target, $tree_id = null, $position = false) {

        // lazy connection: touch the database only when the data is required for the first time and not at object instantiation
        $this->_init($tree_id);

        // continue only if
        if (

            // source node exists in the lookup array AND
            isset($this->lookup[$source]) &&

            // target node exists in the lookup array OR is 0 (indicating a topmost node)
            (isset($this->lookup[$target]) || $target == 0) &&
            
            // target node is not a child node of the source node (that would cause infinite loop)
            !in_array($target, array_keys($this->get_children($source, $tree_id)))
            
        ) {
        
            // the source's parent node's ID becomes the target node's ID
            $this->lookup[$source][$this->properties['parent_column']] = $target;

            // get source node's children nodes (if any)
            $source_children = $this->get_children($source, $tree_id);

            // this array will hold the nodes we need to move
            // by default we add the source node to it
            $sources = array($this->lookup[$source]);

            // iterate through source node's children
            foreach ($source_children as $child) {

                // save them for later use
                $sources[] = $this->lookup[$child[$this->properties['id_column']]];

                // for now, remove them from the lookup array
                unset($this->lookup[$child[$this->properties['id_column']]]);

            }

            // the value with which nodes outside the boundary set below, are to be updated with
            $source_rl_difference =

                $this->lookup[$source][$this->properties['right_column']] -

                $this->lookup[$source][$this->properties['left_column']]

                + 1;

            // set the boundary - nodes having their "left"/"right" values outside this boundary will be affected by
            // the insert, and will need to be updated
            $source_boundary = $this->lookup[$source][$this->properties['left_column']];

            // lock table to prevent other sessions from modifying the data and thus preserving data integrity
            $this->connection->sql('LOCK TABLE ' . $this->properties['table_name'] . ' WRITE');

            // we'll multiply the "left" and "right" values of the nodes we're about to move with "-1", in order to
            // prevent the values being changed further in the script
            $move_qr = '

                UPDATE
                    ' . $this->properties['table_name'] . '
                SET
                    ' . $this->properties['left_column'] . ' = ' . $this->properties['left_column'] . ' * -1,
                    ' . $this->properties['right_column'] . ' = ' . $this->properties['right_column'] . ' * -1
                WHERE
                    ' . $this->properties['left_column'] . ' >= ' . $this->lookup[$source][$this->properties['left_column']] . ' AND
                    ' . $this->properties['right_column'] . ' <= ' . $this->lookup[$source][$this->properties['right_column']] . '

            '; 

            if($tree_id){
                $move_qr .= " AND tree_id = " . $tree_id ; 
            }

            $this->connection->query($move_qr);

            // remove the source node from the list
            unset($this->lookup[$source]);

            // iterate through the remaining nodes in the lookup array
            foreach ($this->lookup as $id=>$properties) {

                // if the "left" value of node is outside the boundary
                if ($this->lookup[$id][$this->properties['left_column']] > $source_boundary)

                    // decrement it
                    $this->lookup[$id][$this->properties['left_column']] -= $source_rl_difference;

                // if the "right" value of item is outside the boundary
                if ($this->lookup[$id][$this->properties['right_column']] > $source_boundary)

                    // decrement it
                    $this->lookup[$id][$this->properties['right_column']] -= $source_rl_difference;

            }

            $update_left_qr = '

                UPDATE
                    ' . $this->properties['table_name'] . '
                SET
                    ' . $this->properties['left_column'] . ' = ' . $this->properties['left_column'] . ' - ' . $source_rl_difference . '
                WHERE
                    ' . $this->properties['left_column'] . ' > ' . $source_boundary . '

            ';

            $update_right_qr = '

                UPDATE
                    ' . $this->properties['table_name'] . '
                SET
                    ' . $this->properties['right_column'] . ' = ' . $this->properties['right_column'] . ' - ' . $source_rl_difference . '
                WHERE
                    ' . $this->properties['right_column'] . ' > ' . $source_boundary . '

            ';

            if($tree_id){
                $update_left_qr .= " AND tree_id = " . $tree_id ; 
                $update_right_qr .= " AND tree_id = " . $tree_id ; 
            }

            // update the nodes in the database having their "left"/"right" values outside the boundary
            $this->connection->query($update_left_qr);

            $this->connection->query($update_right_qr);

            // get children nodes of target node (first level only)
            $target_children = $this->get_children((int)$target, $tree_id, true);
            
            // if node is to be inserted in the default position (as the last of target node's children nodes)
            if ($position === false)

                // give a numerical value to the position
                $position = count($target_children);

            // if a custom position was specified
            else {

                // make sure given position is an integer value
                $position = (int)$position;

                // if position is a bogus number
                if ($position > count($target_children) || $position < 0)

                    // use the default position (as the last of the target node's children)
                    $position = count($target_children);

            }

            // because of the insert, some nodes need to have their "left" and/or "right" values adjusted

            // if target node has no children nodes OR the node is to be inserted as the target node's first child node
            if (empty($target_children) || $position == 0)

                // set the boundary - nodes having their "left"/"right" values outside this boundary will be affected by
                // the insert, and will need to be updated
                // if parent is not found (meaning that we're inserting a topmost node) set the boundary to 0
                $target_boundary = isset($this->lookup[$target]) ? $this->lookup[$target][$this->properties['left_column']] : 0;

            // if target has any children nodes and/or the node needs to be inserted at a specific position
            else {
            
                // find the target's child node that currently exists at the position where the new node needs to be inserted to
                $slice = array_slice($target_children, $position - 1, 1);

                $target_children = array_shift($slice);

                // set the boundary - nodes having their "left"/"right" values outside this boundary will be affected by
                // the insert, and will need to be updated
                $target_boundary = $target_children[$this->properties['right_column']];

            }

            // iterate through the records in the lookup array
            foreach ($this->lookup as $id => $properties) {

                // if the "left" value of node is outside the boundary
                if ($properties[$this->properties['left_column']] > $target_boundary)

                    // increment it
                    $this->lookup[$id][$this->properties['left_column']] += $source_rl_difference;

                // if the "left" value of node is outside the boundary
                if ($properties[$this->properties['right_column']] > $target_boundary)

                    // increment it
                    $this->lookup[$id][$this->properties['right_column']] += $source_rl_difference;

            }

            $modify_other_left_qr = '

                UPDATE
                    ' . $this->properties['table_name'] . '
                SET
                    ' . $this->properties['left_column'] . ' = ' . $this->properties['left_column'] . ' + ' . $source_rl_difference . '
                WHERE
                    ' . $this->properties['left_column'] . ' > ' . $target_boundary . '

            ';

            $modify_other_right_qr = '

                UPDATE
                    ' . $this->properties['table_name'] . '
                SET
                    ' . $this->properties['right_column'] . ' = ' . $this->properties['right_column'] . ' + ' . $source_rl_difference . '
                WHERE
                    ' . $this->properties['right_column'] . ' > ' . $target_boundary . '

            ';

            if($tree_id){
                $modify_other_left_qr .= " AND tree_id = " . $tree_id ; 
                $modify_other_right_qr .= " AND tree_id = " . $tree_id ; 
            }
            // update the nodes in the database having their "left"/"right" values outside the boundary
            $this->connection->query($modify_other_left_qr);

            $this->connection->query($modify_other_right_qr);

            // finally, the nodes that are to be inserted need to have their "left" and "right" values updated
            $shift = $target_boundary - $source_boundary + 1;

            // iterate through the nodes to be inserted
            foreach ($sources as $properties) {

                // update "left" value
                $properties[$this->properties['left_column']] += $shift;

                // update "right" value
                $properties[$this->properties['right_column']] += $shift;

                // add the item to our lookup array
                $this->lookup[$properties[$this->properties['id_column']]] = $properties;

            }

            $update_entries_left_qr = '

                UPDATE
                    ' . $this->properties['table_name'] . '
                SET
                    ' . $this->properties['left_column'] . ' = (' . $this->properties['left_column'] . ' - ' . $shift . ') * -1,
                    ' . $this->properties['right_column'] . ' = (' . $this->properties['right_column'] . ' - ' . $shift . ') * -1
                WHERE
                    ' . $this->properties['left_column'] . ' < 0

            '; 

            $update_entries_right_qr = '

                UPDATE
                    ' . $this->properties['table_name'] . '
                SET
                    ' . $this->properties['parent_column'] . ' = ' . $target . '
                WHERE
                    ' . $this->properties['id_column'] . ' = ' . $source . '

            ';

            if($tree_id){
                $update_entries_left_qr .= " AND tree_id = " . $tree_id ; 
                $update_entries_right_qr .= " AND tree_id = " . $tree_id ; 
            }
            // also update the entries in the database
            // (notice that we're subtracting rather than adding and that finally we multiply by -1 so that the values
            // turn positive again)
            $this->connection->query($update_entries_left_qr);

            // finally, update the parent of the source node
            $this->connection->query($update_entries_right_qr);

            // release table lock
            $this->connection->sql('UNLOCK TABLES');

            // reorder the lookup array
            $this->_reorder_lookup_array();

            // return true as everything went well
            return true;

        }

        // if scripts gets this far, return false as something must've went wrong
        return false;

    }

    /**
     *  Transforms a node and it's subnodes to an ordered/unordered list.
     *
     *  <code>
     *  // include the php file
     *  require 'path/to/Zebra_Mptt.php';
     *
     *  // instantiate the class
     *  $mptt = new Zebra_Mptt();
     *
     *  // make a list out of all nodes as an ordered list and with the
     *  // main list having the class "mylist"
     *  echo $mptt->to_list(0, 'ol', 'class="mylist"');
     *  </code>
     *
     *  @param  integer     $node           The ID of a starting node.
     *
     *                                      "0" means "all nodes".
     *
     *  @param  string      $list_type      (Optional) Can be either "ul" (for an unordered list) or "ol" (for an ordered
     *                                      list).
     *
     *                                      Default is "ul".
     *
     *  @param  string      $attributes     Additional HTML attributes to set for the main list, like "class" or "style".
     *
     *  @return string
     *
     *  @since  2.2.3
     */
    function to_list($node, $tree_id = null, $list_type = 'ul', $attributes = '') {

        // if node is an ID, get the children nodes
        //  (when called recursively this is an array)
        if (!is_array($node)) $node = $this->get_tree($node, $tree_id);

        // if there are any elements
        if (!empty($node)) {

            // start generating the output
            $out = '<' . $list_type . ($attributes != '' ? ' ' . $attributes : '') . '>';

            // iterate through each node
            foreach ($node as $key => $elem)

                // generate output and if the node has children nodes, call this method recursively
                $out .= '<li>' . $elem[$this->properties['id_column']] . ':' . $elem[$this->properties['title_column']] . (is_array($elem['children']) ? $this->to_list($elem['children'], $list_type) : '') . '</li>';

            // return generated output
            return $out . '</' . $list_type . '>';

        }

    }

    /**
     *  Reads the data from the MySQL table and creates a lookup array. Searches will be done in the lookup array
     *  rather than always querying the database.
     *  @todo This shall initialize according to the type
     *  @param integer 
     *  @param bool 
     *  @return void
     *
     *  @access private
     */
    public function _init($tree_id = null, $init_again = FALSE) {
        // if the results are not already cached
        if (!isset($this->lookup) || $init_again) {
            $qr = 'SELECT * FROM' . $this->properties['table_name'];
            if($tree_id){ 
                $qr .= ' WHERE tree_id = ' . $tree_id ; 
            }
            $qr .= ' ORDER BY ' . $this->properties['left_column'];
            // fetch data from the database
            $result = $this->connection->query($qr);
            $this->lookup = array();
            // iterate through the found records
            if($result){
                foreach ($result as $r) {
                    $r = get_object_vars($r);
                    $this->lookup[$r[$this->properties['id_column']]] = $r;
                }
            }
        }
    }

    /**
     *  Updates the lookup array after inserts and deletes.
     *
     *  @return void
     *
     *  @access private
     */
    function _reorder_lookup_array() {

        // re-order the lookup array

        // iterate through the nodes in the lookup array
        foreach ($this->lookup as $properties)

            // create a new array with the name of "left" column, having the values from the "left" column
            ${$this->properties['left_column']}[] = $properties[$this->properties['left_column']];

        // order the array by the left column
        // in the ordering process, the keys are lost
        array_multisort(${$this->properties['left_column']}, SORT_ASC, $this->lookup);

        $tmp = array();

        // iterate through the existing nodes
        foreach ($this->lookup as $properties)

            // and save them to a different array, this time with the correct ID
            $tmp[$properties[$this->properties['id_column']]] = $properties;

        // the updated lookup array
        $this->lookup = $tmp;

    }

    public function shape_query($data){
        $qr = "INSERT INTO " . $this->table_name . " "; 

        foreach ($data as $field => $value) {
            $fields_array[] = $field;
            $values_array[] = $value; 
        }

        $qr .= "(";
        $last_field = end($fields_array); 
        foreach ($fields_array as $f) {
            $qr .= "`$f`";
            if($f != $last_field){
                $qr .= ", ";
            }
        }
        $qr .= ") VALUES (";

        end($values_array);
        $last_val = key($values_array);
        foreach ($values_array as $k => $v) {
            if(is_string($v)){
                $qr .= "'$v'";
            }else if(is_numeric($v)){
                $qr .= "$v";
            } 

            if($k != $last_val){
                $qr .= ", ";
            }   
        }

        $qr .= ");";
        return $qr;
    }

}

?>
