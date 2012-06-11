<?php

/*
 * Copyright 2012 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

abstract class DiffusionRawDiffQuery extends DiffusionQuery {

  private $request;
  private $prevRequest;
  private $timeout;
  private $linesOfContext = 65535;

  final public static function newFromDiffusionRequest(
    DiffusionRequest $request,
    DiffusionRequest $prev_request = null) {
    return parent::newQueryObject(__CLASS__, $request);
    $this->setPreviousRequest($prev_request);
  }

  final public function loadRawDiff() {
    return $this->executeQuery();
  }

  final public function getPreviousRequest() {
    return $this->prevRequest;
  }

  final public function setPreviousRequest($prev_request) {
    $this->prevRequest = $prev_request;
  }

  final public function setTimeout($timeout) {
    $this->timeout = $timeout;
    return $this;
  }

  final public function getTimeout() {
    return $this->timeout;
  }

  final public function setLinesOfContext($lines_of_context) {
    $this->linesOfContext = $lines_of_context;
    return $this;
  }

  final public function getLinesOfContext() {
    return $this->linesOfContext;
  }

}
