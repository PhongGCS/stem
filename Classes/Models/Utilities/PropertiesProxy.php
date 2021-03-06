<?php

namespace Stem\Models\Utilities;

use Carbon\Carbon;
use Stem\Core\Context;
use Stem\Models\InvalidPropertiesException;
use Stem\Models\Post;
use Stem\Models\Term;

class PropertiesProxy {
	/** @var array */
	private $props;

	/** @var Post */
	private $post;

	/** @var bool  */
	private $readOnly = false;

	/** @var string[]  */
	private $readOnlyProps = [];

	/** @var int  */
	private $index = 0;

	public function __construct($post, $props, $readOnly = false, $readOnlyProps = [], $index = -1) {
		$this->readOnly = $readOnly;
		$this->post = $post;
		$this->props = $props;
		$this->readOnlyProps = $readOnlyProps;
		$this->index = $index;
	}

	private function getField($name) {
		$field = $this->props[$name];
		if (in_array($field['type'], ['group', 'repeater'])) {
			if ($field['type'] == 'repeater') {
				return new RepeaterPropertiesProxy($this->post, $field, $this->readOnly);
			} else {
				return new PropertiesProxy($this->post, $field['fields'], $this->readOnly);
			}
		} else {
			$acfField = ($this->index >= 0) ? str_replace('{INDEX}',$this->index,$field['field']) : $field['field'];
			$val = $this->post->getField($acfField, null, arrayPath($field, 'default_value', null));

			if (!empty($val)) {
				if (in_array($field['type'], ['image', 'file', 'post_object', 'page', 'read_only_post_field'])) {
					if (is_array($val)) {
						$result = [];
						foreach($val as $id) {
							$result[] = ($id instanceof \WP_Post) ? Context::current()->modelForPost($id) : Context::current()->modelForPostID($id);
						}

						$val = $result;
					} else {
						$val = ($val instanceof \WP_Post) ? Context::current()->modelForPost($val) : Context::current()->modelForPostID($val);
					}
				} else if (($field['type'] == 'date_picker') || ($field['type'] == 'date_time_picker')) {
					try {
						$val = Carbon::parse($val, Context::timezone());
					} catch (\Exception $ex) {
						$val = Carbon::createFromFormat('d/m/Y', $val, Context::timezone());
					}
				} else if ($field['type'] == 'taxonomy') {
					if (isset($field['taxonomy'])) {
						$val = Term::term(Context::current(), $val, $field['taxonomy']);
					}
				} else if ($field['type'] == 'relationship') {
					$related = [];
					foreach($val as $valId) {
						$related[] = ($valId instanceof \WP_Post) ? Context::current()->modelForPost($valId) : Context::current()->modelForPostID($valId);
					}

					$val = $related;
				}
			}

			return $val;
		}
	}

	public function __get($name) {
		if ($this->__isset($name)) {
			return $this->getField($name);
		}

		return null;
	}

	public function __set($name, $value) {
		if ($this->__isset($name)) {
			if ($this->readOnly && isset($this->readOnlyProps[$name])) {
				throw new InvalidPropertiesException("The property '$name' is read-only.");
			}

			$field = $this->props[$name];
			if (in_array($field['type'], ['group', 'repeater'])) {
				throw new InvalidPropertiesException("Property {$name} is read-only and cannot be assigned to.");
			} else {
				if (in_array($field['type'], ['image', 'file', 'post_object', 'page', 'read_only_post_field'])) {
					if (empty($field['multiple']) && is_array($value)) {
						throw new InvalidPropertiesException("Property {$name} does not allow multiple values.");
					} else if (!empty($field['multiple']) && !is_array($value)) {
						throw new InvalidPropertiesException("Property {$name} should be assigned an array and not a single model instance.");
					}

					if ($value instanceof Post) {
						$value = $value->id;
					} else if (is_array($value)) {
						$newVal = [];
						foreach($value as $model) {
							$newVal[] = $model->id;
						}

						$value = $newVal;
					}
				} else if (($field['type'] == 'date_picker') || ($field['type'] == 'date_time_picker')) {
					if ($value instanceof Carbon) {
						$value->setTimezone(get_option('timezone_string'));
						$value = $value->format("Y-m-d H:i:s");
					}
				} else if ($field['type'] == 'taxonomy') {
					if ($value instanceof Term) {
						$value = $value->id();
					}
				}

				$this->post->updateField($field['field'], $value);
			}
		} else {
			throw new InvalidPropertiesException("Unknown property '$name'.");
		}
	}

	public function __isset($name) {
		if (isset($this->readOnlyProps[$name]) || isset($this->props[$name])) {
			return true;
		}

		return false;
	}
}