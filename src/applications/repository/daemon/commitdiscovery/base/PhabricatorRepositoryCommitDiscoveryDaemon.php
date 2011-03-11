<?php

/*
 * Copyright 2011 Facebook, Inc.
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

abstract class PhabricatorRepositoryCommitDiscoveryDaemon
  extends PhabricatorRepositoryDaemon {

  private $repository;
  private $commitCache = array();

  final protected function getRepository() {
    return $this->repository;
  }

  final public function run() {
    $this->repository = $this->loadRepository();

    $sleep = 15;
    while (true) {
      $found = $this->discoverCommits();
      if ($found) {
        $sleep = 15;
      } else {
        $sleep = min($sleep + 15, 60 * 15);
      }
      $this->sleep($sleep);
    }
  }

  protected function isKnownCommit($target) {
    if (isset($this->commitCache[$target])) {
      return true;
    }

    $commit = id(new PhabricatorRepositoryCommit())->loadOneWhere(
      'repositoryPHID = %s AND commitIdentifier = %s',
      $this->getRepository()->getPHID(),
      $target);

    if (!$commit) {
      return false;
    }

    $this->commitCache[$target] = true;
    while (count($this->commitCache) > 16) {
      array_shift($this->commitCache);
    }

    return true;
  }

  protected function recordCommit($commit_identifier, $epoch) {
    $repository = $this->getRepository();

    $commit = new PhabricatorRepositoryCommit();
    $commit->setRepositoryPHID($repository->getPHID());
    $commit->setCommitIdentifier($commit_identifier);
    $commit->setEpoch($epoch);

    try {
      $commit->save();
      $event = new PhabricatorTimelineEvent(
        'cmit',
        array(
          'id' => $commit->getID(),
        ));
      $event->recordEvent();

      $this->commitCache[$commit_identifier] = true;
    } catch (AphrontQueryDuplicateKeyException $ex) {
      // Ignore. This can happen because we discover the same new commit
      // more than once when looking at history, or because of races or
      // data inconsistency or cosmic radiation; in any case, we're still
      // in a good state if we ignore the failure.
    }

    $this->stillWorking();
  }

  abstract protected function discoverCommits();

}