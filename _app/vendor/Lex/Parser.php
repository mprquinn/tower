<?php
/**
 * Part of the Lex Template Parser
 *
 * @author      Dan Horrigan
 * @author      Jack McDade
 * @author      Fred LeBlanc
 * @author      Mubashar Iqbal
 * @license     MIT License
 * @copyright   2011 - 2012 Dan Horrigan
 * @copyright   2013 Statamic (the Statamic-specific changes)
 */

namespace Lex;

class Parser
{
    protected $allowPhp = false;
    protected $regexSetup = false;
    protected $scopeGlue = '.';
    protected $tagRegex = '';
    protected $cumulativeNoparse = false;

    protected $inCondition = false;

    protected $variableRegex = '';
    protected $variableLoopRegex = '';
    protected $variableTagRegex = '';

    protected $callbackTagRegex = '';
    protected $callbackLoopTagRegex = '';
    protected $callbackNameRegex = '';
    protected $callbackBlockRegex = '';

    protected $noparseRegex = '';

    protected $recursiveRegex = '';

    protected $conditionalRegex = '';
    protected $conditionalElseRegex = '';
    protected $conditionalEndRegex = '';
    protected $conditionalData = array();
    protected $conditionalNotRegex = '';
    protected $conditionalExistsRegex = '';

    protected static $extractions = array(
        'noparse' => array(),
    );

    protected static $data = null;
    protected static $original_text = null;
    protected static $callbackData = array();

    /**
     * The main Lex parser method.  Essentially acts as dispatcher to
     * all of the helper parser methods.
     *
     * @param  string        $text      Text to parse
     * @param  array|object  $data      Array or object to use
     * @param  mixed         $callback  Callback to use for Callback Tags
     * @param  boolean       $allowPhp  Should we allow PHP?
     * @return string
     */
    public function parse($text, $data = array(), $callback = false, $allowPhp = false)
    {
        // <statamic>
        // use : as scope-glue
        $this->scopeGlue = ':';
        // </statamic>

        $this->setupRegex();
        $this->allowPhp = $allowPhp;

        // Is this the first time parse() is called?
        if (self::$data === null) {
            // Let's store the local data array for later use.
            self::$data = $data;
        } else {
            // Let's merge the current data array with the local scope variables
            // So you can call local variables from within blocks.
            // <statamic>
            // data should never have numeric keys, so using union operator as it's faster
            $data = $data + self::$data;
            // </statamic>

            // Since this is not the first time parse() is called, it's most definately a callback,
            // let's store the current callback data with the the local data
            // so we can use it straight after a callback is called.
            self::$callbackData = $data;
            
            // <statamic>
            // Save the original text coming in so that we can parse it recursively
            // later on without this needing to be within a callback
            self::$original_text = $text;
            // </statamic>
        }

        // The parseConditionals method executes any PHP in the text, so clean it up.
        if (! $allowPhp) {
            $text = str_replace(array('<?', '?>'), array('&lt;?', '?&gt;'), $text);
        }

        // <statamic>
        // reverse the order of no-parse and commenting
        $text = $this->extractNoparse($text);
        $text = $this->parseComments($text);
        // </statamic>

        $text = $this->extractLoopedTags($text, $data, $callback);

        // Order is important here.  We parse conditionals first as to avoid
        // unnecessary code from being parsed and executed.
        $text = $this->parseConditionals($text, $data, $callback);
        $text = $this->injectExtractions($text, 'looped_tags');
        $text = $this->parseVariables($text, $data, $callback);
        $text = $this->injectExtractions($text, 'callback_blocks');

        if ($callback) {
            $text = $this->parseCallbackTags($text, $data, $callback);
        }

        // To ensure that {{ noparse }} is never parsed even during consecutive parse calls
        // set $cumulativeNoparse to true and use self::injectNoparse($text); immediately
        // before the final output is sent to the browser
        if (! $this->cumulativeNoparse) {
            $text = $this->injectExtractions($text);
        }

        // <statamic>
        // get tag-pairs with parameters to work
        if (strpos($text, "{{") !== false)  {
            $text = $this->parseCallbackTags($text, $data, null);
        }
        // </statamic>

        return $text;
    }

    /**
     * Removes all of the comments from the text.
     *
     * @param  string $text Text to remove comments from
     * @return string
     */
    public function parseComments($text)
    {
        $this->setupRegex();

        return preg_replace('/\{\{#.*?#\}\}/s', '', $text);
    }

    /**
     * Recursively parses all of the variables in the given text and
     * returns the parsed text.
     *
     * @param  string       $text Text to parse
     * @param  array|object $data Array or object to use
     * @param  callable  $callback  Callback function to call
     * @return string
     */
    public function parseVariables($text, $data, $callback = null)
    {
        $this->setupRegex();
        
        // <statamic>
        // allow avoid tag parsing
        $noparse = array();
        if (isset($data['_noparse'])) {
            $noparse = \Helper::ensureArray($data['_noparse']);
        }
        // </statamic>

        /**
         * $data_matches[][0][0] is the raw data loop tag
         * $data_matches[][0][1] is the offset of raw data loop tag
         * $data_matches[][1][0] is the data variable (dot notated)
         * $data_matches[][1][1] is the offset of data variable
         * $data_matches[][2][0] is the content to be looped over
         * $data_matches[][2][1] is the offset of content to be looped over
         */
        if (preg_match_all($this->variableLoopRegex, $text, $data_matches, PREG_SET_ORDER + PREG_OFFSET_CAPTURE)) {
            foreach ($data_matches as $match) {
                // <statamic>
                // if variable is in the no-parse list, don't parse it
                $var_name = (strpos($match[1][0], '|') !== false) ? substr($match[1][0], 0, strpos($match[1][0], '|')) : $match[1][0];
                
                if (in_array($var_name, $noparse)) {
                    $text = $this->createExtraction('noparse', $match[0][0], $match[2][0], $text);
                    continue;
                }
                // </statamic>
                
                $loop_data = $this->getVariable($match[1][0], $data);
                if ($loop_data) {
                    $looped_text = '';
                    $index = 0;

                    // <statamic>
                    // is this data an array?
                    if (is_array($loop_data)) {
                        // yes
                        $total_results = count($loop_data);

                        foreach ($loop_data as $loop_key => $loop_value) {
                            $index++;

                            $new_loop = (is_array($loop_value)) ? $loop_value : array($loop_key => $loop_value);

                            // is the value an array?
                            if ( ! is_array($loop_value)) {
                                // no, make it one
                                $loop_value = array(
                                    'value' => $loop_value,
                                    'name' => $loop_value // 'value' alias (legacy)
                                );
                            }

                            // set contextual iteration values
                            $loop_value['key']            = $loop_key;
                            $loop_value['index']          = $index;
                            $loop_value['zero_index']     = $index - 1;
                            $loop_value['total_results']  = $total_results;
                            $loop_value['first']          = ($index === 1) ? true : false;
                            $loop_value['last']           = ($index === $loop_value['total_results']) ? true : false;

                            // merge this local data with callback data before performing actions
                            $loop_value = $loop_value + self::$callbackData;

                            // perform standard actions
                            $str = $this->extractLoopedTags($match[2][0], $loop_value, $callback);
                            $str = $this->parseConditionals($str, $loop_value, $callback);
                            $str = $this->injectExtractions($str, 'looped_tags');
                            $str = $this->parseVariables($str, $loop_value, $callback);

                            if (!is_null($callback)) {
                                $str = $this->parseCallbackTags($str, $new_loop, $callback);
                            }

                            $looped_text .= $str;
                        }

                        $text = preg_replace('/'.preg_quote($match[0][0], '/').'/m', addcslashes($looped_text, '\\$'), $text, 1);

                    } else {
                        // no, so this is just a value, we're done here
                        return $loop_data;
                    }
                    // </statamic>
                } else { // It's a callback block.
                    // Let's extract it so it doesn't conflict
                    // with the local scope variables in the next step.
                    $text = $this->createExtraction('callback_blocks', $match[0][0], $match[0][0], $text);
                }
            }
        }

        /**
         * $data_matches[0] is the raw data tag
         * $data_matches[1] is the data variable (dot notated)
         */
        if (preg_match_all($this->variableTagRegex, $text, $data_matches)) {
            foreach ($data_matches[1] as $index => $var) {
                // <statamic>
                // account for modifiers
                $var_pipe  = strpos($var, '|');
                $var_name  = ($var_pipe !== false) ? substr($var, 0, $var_pipe) : $var;
                // </statamic>
                
                if (($val = $this->getVariable($var, $data, '__lex_no_value__')) !== '__lex_no_value__') {
                    if (is_array($val)) {
                        $val = "";
                        \Log::error("Cannot display tag `" . $data_matches[0][$index] . "` because it is a list, not a single value. To display list values, use a tag-pair.", "template", "parser");
                    }

                    // <statamic>
                    // if variable is in the no-parse list, extract it
                    // handles the very-special |noparse modifier
                    if (($var_pipe !== false && in_array('noparse', array_slice(explode('|', $var), 1))) || in_array($var_name, $noparse)) {
                        $text = $this->createExtraction('noparse', $data_matches[0][$index], $val, $text);
                    } else {
                        // </statamic>
                        $text = str_replace($data_matches[0][$index], $val, $text);
                        // <statamic>
                    }
                    // </statamic>
                }
            }
        }

        // <statamic>
        // we need to look for parameters on plain-old non-callback variable tags
        // right now, this only applies to `format` parameters for dates
        $regex = '/\{\{\s*('.$this->variableRegex.')(\s+.*?)?\s*\}\}/ms';
        if (preg_match_all($regex, $text, $data_matches, PREG_SET_ORDER + PREG_OFFSET_CAPTURE)) {
            foreach ($data_matches as $match) {

                // grab some starting values & init variables
                $parameters  = array();
                $tag         = $match[0][0];
                $name        = $match[1][0];

                // is this not the content tag, and is the value known?
                if ($name != 'content' && isset($data[$name])) {
                    // it is, are there parameters?
                    if (isset($match[2])) {
                        // there are, make a backup of our $data
                        $cb_data = $data;

                        // is $data an array?
                        if (is_array($data)) {
                            // it is, have we had callback data before?
                            if ( !empty(self::$callbackData)) {
                                // we have, merge it all together
                                $cb_data = $data + self::$callbackData;
                            }

                            // grab the raw string of parameters
                            $raw_params = $this->injectExtractions($match[2][0], '__cond_str');

                            // parse them into an array
                            $parameters = $this->parseParameters($raw_params, $cb_data, $callback);
                        } elseif (is_string($data)) {
                            $text = str_replace($tag, $data, $text);
                        }
                    }

                    // check for certain parameters and do what they should do
                    if (isset($parameters['format'])) {
                        $text = str_replace($tag, \Date::format($parameters['format'], $data[$name]), $text);
                    }
                }
            }
        }
        // </statamic>

        return $text;
    }

    /**
     * Parses all Callback tags, and sends them through the given $callback.
     *
     * @param  string $text           Text to parse
     * @param  array  $data           An array of data to use
     * @param  mixed  $callback       Callback to apply to each tag
     * @return string
     */
    public function parseCallbackTags($text, $data, $callback)
    {
        $this->setupRegex();
        $inCondition = $this->inCondition;

        if ($inCondition) {
            $regex = '/\{\s*('.$this->variableRegex.')(\s+.*?)?\s*\}/ms';
        } else {
            $regex = '/\{\{\s*('.$this->variableRegex.')(\s+.*?)?\s*(\/)?\}\}/ms';
        }

        // <statamic>
        // define a variable of collective callback data
        $cb_data = $data;
        // </statamic>

        /**
         * $match[0][0] is the raw tag
         * $match[0][1] is the offset of raw tag
         * $match[1][0] is the callback name
         * $match[1][1] is the offset of callback name
         * $match[2][0] is the parameters
         * $match[2][1] is the offset of parameters
         * $match[3][0] is the self closure
         * $match[3][1] is the offset of closure
         */
        while (preg_match($regex, $text, $match, PREG_OFFSET_CAPTURE)) {
            // <statamic>
            // update the collective data if it's different
            if ( !empty(self::$callbackData)) {
                $cb_data = $data + self::$callbackData;
            }
            // </statamic>

            $selfClosed = false;
            $parameters = array();
            $tag = $match[0][0];
            $start = $match[0][1];
            $name = $match[1][0];
            if (isset($match[2])) {
                $raw_params = $this->injectExtractions($match[2][0], '__cond_str');
                $parameters = $this->parseParameters($raw_params, $cb_data, $callback);

                // <statamic>
                // replace variables within parameters
                foreach ($parameters as $param_key => $param_value) {
                    if (preg_match_all('/(\{\s*'.$this->variableRegex.'\s*\})/', $param_value, $param_matches)) {
                        $param_value = str_replace('{', '{{', $param_value);
                        $param_value = str_replace('}', '}}', $param_value);
                        $param_value = $this->parseVariables($param_value, $data);
                        $parameters[$param_key] = $this->parseCallbackTags($param_value, $data, $callback);
                    }
                }
                // </statamic>
            }

            if (isset($match[3])) {
                $selfClosed = true;
            }
            $content = '';

            $temp_text = substr($text, $start + strlen($tag));
            if (preg_match('/\{\{\s*\/'.preg_quote($name, '/').'\s*\}\}/m', $temp_text, $match, PREG_OFFSET_CAPTURE) && ! $selfClosed) {

                $content = substr($temp_text, 0, $match[0][1]);
                $tag .= $content.$match[0][0];

                // Is there a nested block under this one existing with the same name?
                $nested_regex = '/\{\{\s*('.preg_quote($name, '/').')(\s.*?)\}\}(.*?)\{\{\s*\/\1\s*\}\}/ms';
                if (preg_match($nested_regex, $content.$match[0][0], $nested_matches)) {
                    $nested_content = preg_replace('/\{\{\s*\/'.preg_quote($name, '/').'\s*\}\}/m', '', $nested_matches[0]);
                    $content = $this->createExtraction('nested_looped_tags', $nested_content, $nested_content, $content);
                }
            }

            // <statamic>
            // we'll be checking on replacement later, so initialize it
            $replacement = null;

            // now, check to see if a callback should happen
            if ($callback) {
                // </statamic>
                $replacement = call_user_func_array($callback, array($name, $parameters, $content, $data));
                $replacement = $this->parseRecursives($replacement, $content, $callback);
                // <statamic>
            }
            // </statamic>

            // <statamic>
            // look for tag pairs and (plugin) callbacks
            if ($name != "content" && !$replacement) {
                // is the callback a variable in our data set?
                if (isset($data[$name])) {
                    // it is, start with the value(s)
                    $values = $data[$name];

                    // is this a tag-pair?
                    if (is_array($values)) {
                        // yes it is
                        // there might be parameters that will control how this
                        // tag-pair's data is filtered/sorted/limited/etc,
                        // look for those and apply those as needed

                        // exact result grabbing ----------------------------------

                        // first only
                        if (isset($parameters['first'])) {
                            $values = array_splice($values, 0, 1);
                        }

                        // last only
                        if (isset($parameters['last'])) {
                            $values = array_splice($values, -1, 1);
                        }

                        // specific-index only
                        if (isset($parameters['index'])) {
                            $values = array_splice($values, $parameters['index']-1, 1);
                        }

                        // now filter remaining values ----------------------------

                        // excludes
                        if (isset($parameters['exclude'])) {
                            $exclude = array_flip(explode('|', $parameters['exclude']));
                            $values  = array_diff_key($values, $exclude);
                        }

                        // includes
                        if (isset($parameters['include'])) {
                            $include = array_flip(explode('|', $parameters['include']));
                            $values = array_intersect_key($values, $include);
                        }

                        // now sort remaining values ------------------------------

                        // field to sort by
                        if (isset($parameters['sort_by'])) {
                            $sort_field = $parameters['sort_by'];

                            if ($sort_field == 'random') {
                                shuffle($values);
                            } else {
                                usort($values, function($a, $b) use ($sort_field) {
                                    $a_value = (isset($a[$sort_field])) ? $a[$sort_field] : null;
                                    $b_value = (isset($b[$sort_field])) ? $b[$sort_field] : null;

                                    return \Helper::compareValues($a_value, $b_value);
                                });
                            }
                        }

                        // direction to sort by
                        if (isset($parameters['sort_dir']) && $parameters['sort_dir'] == 'desc') {
                            $values = array_reverse($values);
                        }

                        // finally, offset & limit values -------------------------

                        if (isset($parameters['offset']) || isset($parameters['limit'])) {
                            $offset = (isset($parameters['offset'])) ? $parameters['offset'] : 0;
                            $limit  = (isset($parameters['limit'])) ? $parameters['limit'] : null;

                            $values = array_splice($values, $offset, $limit);
                        }


                        // loop over remaining values, adding contextual tags
                        // to each iteration of the loop
                        $i = 0;
                        $total_results = count($values);
                        foreach ($values as $value_key => $value_value) {
                            // increment index iterator
                            $i++;

                            // if this isn't an array, we need to make it one
                            if (!is_array($values[$value_key])) {
                                // not an array, set contextual tags
                                // note: these are for tag-pairs only
                                $values[$value_key] = array(
                                    'key'           => $i - 1,
                                    'value'         => $values[$value_key],
                                    'name'          => $values[$value_key], // 'value' alias (legacy)
                                    'index'         => $i,
                                    'zero_index'    => $i - 1,
                                    'total_results' => $total_results,
                                    'first'         => ($i === 1) ? true : false,
                                    'last'          => ($i === $total_results) ? true : false
                                );
                            }
                        }
                    }

                    // is values not an empty string?
                    if ($values !== "") {
                        // correct, parse the tag found with the value(s) related to it
                        $replacement = $this->parseVariables("{{ $name }}$content{{ /$name }}", array($name => $values), $callback);
                    }

                } else {
                    // nope, this must be a (plugin) callback
                    if (is_null($callback)) {
                        // @todo what does this do?
                        $text = $this->createExtraction('__variables_not_callbacks', $text, $text, $text);
                    } elseif (isset($cb_data[$name])) {
                        // value not found in the data block, so we check the
                        // cumulative callback data block for a value and use that
                        $text = $this->parseVariables($text, $cb_data, $callback);
                        $text = $this->injectExtractions($text, 'callback_blocks');
                    }
                }
            }
            // </statamic>

            if ($inCondition) {
                $replacement = $this->valueToLiteral($replacement);
            }
            $text = preg_replace('/'.preg_quote($tag, '/').'/m', addcslashes($replacement, '\\$'), $text, 1);
            $text = $this->injectExtractions($text, 'nested_looped_tags');
        }

        // <statamic>
        // parse for recursives, as they may not have been parsed for above
        $text = $this->parseRecursives($text, self::$original_text, $callback);
        // </statamic>

        // <statamic>
        // re-inject any extractions we extracted
        if (is_null($callback)) {
            $text = $this->injectExtractions($text, '__variables_not_callbacks');
        }
        // </statamic>

        return $text;
    }

    /**
     * Parses all conditionals, then executes the conditionals.
     *
     * @param  string $text     Text to parse
     * @param  mixed  $data     Data to use when executing conditionals
     * @param  mixed  $callback The callback to be used for tags
     * @return string
     */
    public function parseConditionals($text, $data, $callback)
    {
        $this->setupRegex();
        preg_match_all($this->conditionalRegex, $text, $matches, PREG_SET_ORDER);

        $this->conditionalData = $data;

        /**
         * $matches[][0] = Full Match
         * $matches[][1] = Either 'if', 'unless', 'elseif', 'elseunless'
         * $matches[][2] = Condition
         */
        foreach ($matches as $match) {
            $this->inCondition = true;

            $condition = $match[2];

            // <statamic>
            // do an initial check for callbacks, extract them if found
            if ($callback) {
                if (preg_match_all('/\b(?!\{\s*)('.$this->callbackNameRegex.')(?!\s+.*?\s*\})\b/', $condition, $cb_matches)) {
                    foreach ($cb_matches[0] as $m) {
                        $condition = $this->createExtraction('__cond_callbacks', $m, "{$m}", $condition);
                    }
                }
            }
            // </statamic>

            // Extract all literal string in the conditional to make it easier
            if (preg_match_all('/(["\']).*?(?<!\\\\)\1/', $condition, $str_matches)) {
                foreach ($str_matches[0] as $m) {
                    $condition = $this->createExtraction('__cond_str', $m, $m, $condition);
                }
            }
            $condition = preg_replace($this->conditionalNotRegex, '$1!$2', $condition);

            if (preg_match_all($this->conditionalExistsRegex, $condition, $existsMatches, PREG_SET_ORDER)) {
                foreach ($existsMatches as $m) {
                    $exists = 'true';
                    if ($this->getVariable($m[2], $data, '__doesnt_exist__') === '__doesnt_exist__') {
                        $exists = 'false';
                    }
                    $condition = $this->createExtraction('__cond_exists', $m[0], $m[1].$exists.$m[3], $condition);
                }
            }

            $condition = preg_replace_callback('/\b('.$this->variableRegex.')\b/', array($this, 'processConditionVar'), $condition);

            // <statamic>
            // inject any found callbacks and parse them
            if ($callback) {
                $condition = $this->injectExtractions($condition, '__cond_callbacks');
                $condition = $this->parseCallbackTags($condition, $data, $callback);
            }
            // </statamic>

            // Re-extract the strings that have now been possibly added.
            if (preg_match_all('/(["\']).*?(?<!\\\\)\1/', $condition, $str_matches)) {
                foreach ($str_matches[0] as $m) {
                    $condition = $this->createExtraction('__cond_str', $m, $m, $condition);
                }
            }

            // Re-process for variables, we trick processConditionVar so that it will return null
            $this->inCondition = false;

            // <statamic>
            // replacements -- the preg_replace_callback below is using word boundaries, which
            // will break when one of your original variables gets replaced with a URL path
            // (because word boundaries think slashes are boundaries) -- to fix this, we replace
            // all instances of a literal string in single quotes with a temporary replacement
            $replacements = array();

            // first up, replacing literal strings
            while (preg_match("/('[^']+'|\"[^\"]+\")/", $condition, $replacement_matches)) {
                $replacement_match = $replacement_matches[1];
                $replacement_hash  = md5($replacement_match);

                $replacements[$replacement_hash] = $replacement_match;
                $condition = str_replace($replacement_match, "__temp_replacement_" . $replacement_hash, $condition);
            }

            // next, the original re-processing callback
            // </statamic>
            $condition = preg_replace_callback('/\b('.$this->variableRegex.')\b/', array($this, 'processConditionVar'), $condition);

            // <statamic>
            // finally, replacing our placeholders with the original values
            foreach ($replacements as $replace_key => $replace_value) {
                $condition = str_replace('__temp_replacement_' . $replace_key, $replace_value, $condition);
            }
            // </statamic>

            $this->inCondition = true;

            // Re-inject any strings we extracted
            $condition = $this->injectExtractions($condition, '__cond_str');
            $condition = $this->injectExtractions($condition, '__cond_exists');

            $conditional = '<?php ';

            if ($match[1] == 'unless') {
                $conditional .= 'if ( ! ('.$condition.'))';
            } elseif ($match[1] == 'elseunless') {
                $conditional .= 'elseif ( ! ('.$condition.'))';
            } else {
                $conditional .= $match[1].' ('.$condition.')';
            }

            $conditional .= ': ?>';

            $text = preg_replace('/'.preg_quote($match[0], '/').'/m', addcslashes($conditional, '\\$'), $text, 1);
        }

        $text = preg_replace($this->conditionalElseRegex, '<?php else: ?>', $text);
        $text = preg_replace($this->conditionalEndRegex, '<?php endif; ?>', $text);

        $text = $this->parsePhp($text);
        $this->inCondition = false;

        return $text;
    }

    /**
     * Goes recursively through a callback tag with a passed child array.
     *
     * @param  string $text      - The replaced text after a callback.
     * @param  string $orig_text - The original text, before a callback is called.
     * @param  mixed  $callback
     * @return string $text
     */
    public function parseRecursives($text, $orig_text, $callback)
    {
        // Is there a {{ *recursive [array_key]* }} tag here, let's loop through it.
        if (preg_match($this->recursiveRegex, $text, $match)) {
            $tag = $match[0];
            $array_key = $match[1];
            
            // <statamic>
            // check to see if the recursive variable we're looking for is set
            // within the current data for this run-through, if it isn't, just
            // abort and return the text
            if (!isset(self::$callbackData[$array_key]) || !self::$callbackData[$array_key]) {
                return $text;
            }
            // </statamic>
            
            $next_tag = null;
            $children = self::$callbackData[$array_key];
            $child_count = count($children);
            $count = 1;

            // Is the array not multi-dimensional? Let's make it multi-dimensional.
            if ($child_count == count($children, COUNT_RECURSIVE)) {
                $children = array($children);
                $child_count = 1;
            }

            foreach ($children as $child) {
                $has_children = true;

                // If this is a object let's convert it to an array.
                is_array($child) OR $child = (array) $child;

                // Does this child not contain any children?
                // Let's set it as empty then to avoid any errors.
                if ( ! array_key_exists($array_key, $child)) {
                    $child[$array_key] = array();
                    $has_children = false;
                }

                $replacement = $this->parse($orig_text, $child, $callback, $this->allowPhp);

                // If this is the first loop we'll use $tag as reference, if not
                // we'll use the previous tag ($next_tag)
                $current_tag = ($next_tag !== null) ? $next_tag : $tag;

                // If this is the last loop set the next tag to be empty
                // otherwise hash it.
                $next_tag = ($count == $child_count) ? '' : md5($tag.$replacement);

                $text = str_replace($current_tag, $replacement.$next_tag, $text);

                if ($has_children) {
                    $text = $this->parseRecursives($text, $orig_text, $callback);
                }
                $count++;
            }
        }

        return $text;
    }

    /**
     * Gets or sets the Scope Glue
     *
     * @param  string|null $glue The Scope Glue
     * @return string
     */
    public function scopeGlue($glue = null)
    {
        if ($glue !== null) {
            $this->regexSetup = false;
            $this->scopeGlue = $glue;
        }

        return $this->scopeGlue;
    }

    /**
     * Sets the noparse style. Immediate or cumulative.
     *
     * @param  bool $mode
     * @return void
     */
    public function cumulativeNoparse($mode)
    {
        $this->cumulativeNoparse = $mode;
    }

    /**
     * Injects noparse extractions.
     *
     * This is so that multiple parses can store noparse
     * extractions and all noparse can then be injected right
     * before data is displayed.
     *
     * @param  string $text Text to inject into
     * @return string
     */
    public static function injectNoparse($text)
    {
        if (isset(self::$extractions['noparse'])) {
            foreach (self::$extractions['noparse'] AS $hash => $replacement) {
                if (strpos($text, "noparse_{$hash}") !== FALSE) {
                    $text = str_replace("noparse_{$hash}", $replacement, $text);
                }
            }
        }

        return $text;
    }

    /**
     * This is used as a callback for the conditional parser.  It takes a variable
     * and returns the value of it, properly formatted.
     *
     * @param  array  $match A match from preg_replace_callback
     * @return string
     */
    protected function processConditionVar($match)
    {
        $var = is_array($match) ? $match[0] : $match;
        if (in_array(strtolower($var), array('true', 'false', 'null', 'or', 'and')) or
            strpos($var, '__cond_str') === 0 or
            strpos($var, '__cond_exists') === 0 or
            // <statamic>
            // adds a new temporary replacement to deal with string literals
            strpos($var, '__temp_replacement') === 0 or
            // </statamic>
            is_numeric($var))
        {
            return $var;
        }

        $value = $this->getVariable($var, $this->conditionalData, '__processConditionVar__');

        if ($value === '__processConditionVar__') {
            return $this->inCondition ? $var : 'null';
        }

        return $this->valueToLiteral($value);
    }

    /**
     * This is used as a callback for the conditional parser.  It takes a variable
     * and returns the value of it, properly formatted.
     *
     * @param  array  $match A match from preg_replace_callback
     * @return string
     */
    protected function processParamVar($match)
    {
        return $match[1].$this->processConditionVar($match[2]);
    }

    /**
     * Takes a value and returns the literal value for it for use in a tag.
     *
     * @param  string $value Value to convert
     * @return string
     */
    protected function valueToLiteral($value)
    {
        if (is_object($value) and is_callable(array($value, '__toString'))) {
            return var_export((string) $value, true);
        } elseif (is_array($value)) {
            return !empty($value) ? "true" : "false";
        } else {
            return var_export($value, true);
        }
    }

    /**
     * Sets up all the global regex to use the correct Scope Glue.
     *
     * @return void
     */
    protected function setupRegex()
    {
        if ($this->regexSetup) {
            return;
        }
        $glue = preg_quote($this->scopeGlue, '/');

        // <statamic>
        // expand allowed characters in variable regex
        $this->variableRegex = $glue === '\\.' ? '[a-zA-Z0-9_][|a-zA-Z\-\+\*%\^\/,0-9_'.$glue.']*' : '[a-zA-Z0-9_][|a-zA-Z\-\+\*%\^\/,0-9_\.'.$glue.']*';
        // </statamic>
        $this->callbackNameRegex = $this->variableRegex.$glue.$this->variableRegex;
        $this->variableLoopRegex = '/\{\{\s*('.$this->variableRegex.')\s*\}\}(.*?)\{\{\s*\/\1\s*\}\}/ms';
        $this->variableTagRegex = '/\{\{\s*('.$this->variableRegex.')\s*\}\}/m';

        // <statamic>
        // make the space-anything after the variable regex optional, this allows
        // users to use {{tags}} in addition to {{ tags }} -- weird, I know
        $this->callbackBlockRegex = '/\{\{\s*('.$this->variableRegex.')(\s.*?)?\}\}(.*?)\{\{\s*\/\1\s*\}\}/ms';
        // </statamic>

        $this->recursiveRegex = '/\{\{\s*\*recursive\s*('.$this->variableRegex.')\*\s*\}\}/ms';

        $this->noparseRegex = '/\{\{\s*noparse\s*\}\}(.*?)\{\{\s*\/noparse\s*\}\}/ms';

        $this->conditionalRegex = '/\{\{\s*(if|unless|elseif|elseunless)\s*((?:\()?(.*?)(?:\))?)\s*\}\}/ms';
        $this->conditionalElseRegex = '/\{\{\s*else\s*\}\}/ms';
        $this->conditionalEndRegex = '/\{\{\s*endif\s*\}\}/ms';
        $this->conditionalExistsRegex = '/(\s+|^)exists\s+('.$this->variableRegex.')(\s+|$)/ms';
        $this->conditionalNotRegex = '/(\s+|^)not(\s+|$)/ms';

        $this->regexSetup = true;

        // This is important, it's pretty unclear by the documentation
        // what the default value is on <= 5.3.6
        ini_set('pcre.backtrack_limit', 1000000);
    }

    /**
     * Extracts the noparse text so that it is not parsed.
     *
     * @param  string $text The text to extract from
     * @return string
     */
    protected function extractNoparse($text)
    {
        /**
         * $matches[][0] is the raw noparse match
         * $matches[][1] is the noparse contents
         */
        if (preg_match_all($this->noparseRegex, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $text = $this->createExtraction('noparse', $match[0], $match[1], $text);
            }
        }

        return $text;
    }

    /**
     * Extracts the looped tags so that we can parse conditionals then re-inject.
     *
     * @param string  $text  The text to extract from
     * @param array  $data  Data array to use
     * @param callable  $callback  Callback to call when complete
     * @return string
     */
    protected function extractLoopedTags($text, $data = array(), $callback = null)
    {
        /**
         * $matches[][0] is the raw match
         */
        if (preg_match_all($this->callbackBlockRegex, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                // Does this callback block contain parameters?
                if ($this->parseParameters($match[2], $data, $callback)) {
                    // Let's extract it so it doesn't conflict with local variables when
                    // parseVariables() is called.
                    $text = $this->createExtraction('callback_blocks', $match[0], $match[0], $text);
                } else {
                    $text = $this->createExtraction('looped_tags', $match[0], $match[0], $text);
                }
            }
        }

        return $text;
    }

    /**
     * Extracts text out of the given text and replaces it with a hash which
     * can be used to inject the extractions replacement later.
     *
     * @param  string $type        Type of extraction
     * @param  string $extraction  The text to extract
     * @param  string $replacement Text that will replace the extraction when re-injected
     * @param  string $text        Text to extract out of
     * @return string
     */
    protected function createExtraction($type, $extraction, $replacement, $text)
    {
        $hash = md5($replacement);
        self::$extractions[$type][$hash] = $replacement;

        return str_replace($extraction, "{$type}_{$hash}", $text);
    }


    /**
     * Injects all of the extractions.
     *
     * @param string  $text  Text to inject into
     * @param string  $type  Type of extraction to inject
     * @return string
     */
    protected function injectExtractions($text, $type = null)
    {
        // <statamic>
        // changed === comparison to is_null check
        if (is_null($type)) {
            // </statamic>
            foreach (self::$extractions as $type => $extractions) {
                foreach ($extractions as $hash => $replacement) {
                    if (strpos($text, "{$type}_{$hash}") !== false) {
                        $text = str_replace("{$type}_{$hash}", $replacement, $text);
                        unset(self::$extractions[$type][$hash]);
                    }
                }
            }
        } else {
            if ( ! isset(self::$extractions[$type])) {
                return $text;
            }

            foreach (self::$extractions[$type] as $hash => $replacement) {
                if (strpos($text, "{$type}_{$hash}") !== false) {
                    $text = str_replace("{$type}_{$hash}", $replacement, $text);
                    unset(self::$extractions[$type][$hash]);
                }
            }
        }

        return $text;
    }


    /**
     * Takes a scope-notated key and finds the value for it in the given
     * array or object.
     *
     * @param  string       $key     Dot-notated key to find
     * @param  array|object $data    Array or object to search
     * @param  mixed        $default Default value to use if not found
     * @return mixed
     */
    protected function getVariable($key, $data, $default = null)
    {
        // <statamic>
        // detect modifiers
        $modifiers = null;
        if (strpos($key, "|") !== false) {
            $parts      = explode("|", $key);
            $key        = $parts[0];
            $modifiers  = array_splice($parts, 1);
        }
        // </statamic>

        if (strpos($key, $this->scopeGlue) === false) {
            $parts = explode('.', $key);
        } else {
            $parts = explode($this->scopeGlue, $key);
        }
        foreach ($parts as $key_part) {
            if (is_array($data)) {
                if ( ! array_key_exists($key_part, $data)) {
                    return $default;
                }

                $data = $data[$key_part];
            } elseif (is_object($data)) {
                if ( ! isset($data->{$key_part})) {
                    return $default;
                }

                $data = $data->{$key_part};
            } else {
                return $default;
            }
        }

        // <statamic>
        // execute modifier chain
        if ($modifiers) {
            foreach ($modifiers as $mod) {
                if (strpos($mod, ":") === false) {
                    $modifier = $mod;
                    $modifier_params = array();
                } else {
                    $parts = explode(":", $mod);
                    $modifier = $parts[0];
                    $modifier_params = array_splice($parts, 1);
                }

                try {
                    // load modifier
                    $modifier_obj = \Resource::loadModifier(\Parse::modifierAlias($modifier));

                    // ensure method exists
                    if (!method_exists($modifier_obj, "index")) {
                        throw new \Exception("Improperly formatted modifier object.");
                    }

                    // call method
                    $data = $modifier_obj->index($data, $modifier_params);
                } catch (\Exception $e) {
                    // do nothing
                }
            }
        }
        // </statamic>

        return $data;
    }


    /**
     * Evaluates the PHP in the given string.
     *
     * @param string  $text  Text to evaluate
     * @return string
     * @throws ParsingException
     */
    protected function parsePhp($text)
    {
        ob_start();
        $result = eval('?>'.$text.'<?php ');

        if ($result === false) {
            $output = 'You have a syntax error in your Lex tags. The offending code: ';
            throw new ParsingException($output.str_replace(array('?>', '<?php '), '', $text));
        }

        return ob_get_clean();
    }


    /**
     * Parses a parameter string into an array
     *
     * @param string  $parameters The string of parameters
     * @param array  $data  Array of data
     * @param callable  $callback  Callback function to call
     * @return array
     */
    protected function parseParameters($parameters, $data, $callback)
    {
        $this->conditionalData = $data;
        $this->inCondition = true;
        // Extract all literal string in the conditional to make it easier
        if (preg_match_all('/(["\']).*?(?<!\\\\)\1/', $parameters, $str_matches)) {
            foreach ($str_matches[0] as $m) {
                $parameters = $this->createExtraction('__param_str', $m, $m, $parameters);
            }
        }

        $parameters = preg_replace_callback(
            '/(.*?\s*=\s*(?!__))('.$this->variableRegex.')/is',
            array($this, 'processParamVar'),
            $parameters
        );
        if ($callback) {
            $parameters = preg_replace('/(.*?\s*=\s*(?!\{\s*)(?!__))('.$this->callbackNameRegex.')(?!\s*\})\b/', '$1{$2}', $parameters);
            $parameters = $this->parseCallbackTags($parameters, $data, $callback);
        }

        // Re-inject any strings we extracted
        $parameters = $this->injectExtractions($parameters, '__param_str');
        $this->inCondition = false;

        if (preg_match_all('/(.*?)\s*=\s*(\'|"|&#?\w+;)(.*?)(?<!\\\\)\2/s', trim($parameters), $matches)) {
            $return = array();
            foreach ($matches[1] as $i => $attr) {
                $return[trim($matches[1][$i])] = stripslashes($matches[3][$i]);
            }

            return $return;
        }

        return array();
    }
}