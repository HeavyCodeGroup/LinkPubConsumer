<?php

namespace HeavyCodeGroup\LinkPub\Consumer\Distribution;

class MakefileWriter
{
    protected $rules = array();

    public function newRule($rule) {
        $this->rules[$rule] = array();
    }

    public function appendAction($rule, $action) {
        if (isset($this->rules[$rule])) {
            $this->rules[$rule][] = $action;
        }
    }

    public function appendDependency($rule, $dependency) {
        if (isset($this->rules[$rule])) {
            if (!isset($this->rules[$rule]['depends'])) {
                $this->rules[$rule]['depends'] = array();
            }
            $this->rules[$rule]['depends'][] = $dependency;
        }
    }

    public function getMakefile()
    {
        $mw = $this;

        return implode("\n", array_map(function ($rule) use ($mw) {
            return $mw->getRuleText($rule);
        }, array_keys($this->rules)));
    }

    public function writeMakefile($filename)
    {
        return file_put_contents($filename, $this->getMakefile());
    }

    protected function getRuleText($rule)
    {
        $text = '';
        if (isset($this->rules[$rule])) {
            $text .= "$rule:";
            if (isset($this->rules[$rule]['depends'])) {
                $text .= implode('', array_map(function ($text) {
                    return ' ' . $text;
                }, $this->rules[$rule]['depends']));
            }
            $text .= "\n";
            $i = 0;
            while (isset($this->rules[$rule][$i])) {
                $text .= "\t" . $this->rules[$rule][$i] . "\n";
                $i++;
            }
        }

        return $text;
    }
}
