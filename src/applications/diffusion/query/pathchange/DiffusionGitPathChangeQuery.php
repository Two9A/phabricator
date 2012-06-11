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

final class DiffusionGitPathChangeQuery extends DiffusionPathChangeQuery {

  private $request;
  private $prevRequest;

  protected function executeQuery() {

    $drequest = $this->getRequest();
    $drequest_prev = $this->getPreviousRequest();
    $repository = $drequest->getRepository();

    $commit = $drequest->loadCommit();
    if ($drequest_prev) {
      $commit_ids = array();
      $commit_prev = $drequest_prev->loadCommit();
      $future = $repository->getLocalCommandFuture(
        'log --format="%%H" %s..%s',
        $commit_prev->getCommitIdentifier(),
        $commit->getCommitIdentifier());

      try {
        list($raw_ids) = $future->resolvex();
        foreach (explode("\n", $raw_ids) as $id) {
          if (trim($id)) {
            $commit_ids[] = $id;
          }
        }
      } catch (CommandException $ex) {
        throw $ex;
      }

    }
    else {
      $commit_ids = array($commit->getID());
    }

    $raw_changes = queryfx_all(
      $repository->establishConnection('r'),
      'SELECT c.*, p.path pathName, t.path targetPathName,
          i.commitIdentifier targetCommitIdentifier
        FROM %T c
          LEFT JOIN %T p ON c.pathID = p.id
          LEFT JOIN %T t ON c.targetPathID = t.id
          LEFT JOIN %T i ON c.targetCommitID = i.id
        WHERE c.commitID IN (%Ls) AND isDirect = 1',
      PhabricatorRepository::TABLE_PATHCHANGE,
      PhabricatorRepository::TABLE_PATH,
      PhabricatorRepository::TABLE_PATH,
      $commit->getTableName(),
      $commit_ids);

    $changes = array();

    $raw_changes = isort($raw_changes, 'pathName');
    foreach ($raw_changes as $raw_change) {
      $type = $raw_change['changeType'];
      if ($type == DifferentialChangeType::TYPE_CHILD) {
        continue;
      }

      $change = new DiffusionPathChange();
      $change->setPath(ltrim($raw_change['pathName'], '/'));
      $change->setChangeType($raw_change['changeType']);
      $change->setFileType($raw_change['fileType']);
      $change->setCommitIdentifier($commit->getID());

      $change->setTargetPath(ltrim($raw_change['targetPathName'], '/'));
      $change->setTargetCommitIdentifier($raw_change['targetCommitIdentifier']);

      $changes[] = $change;
    }

    // Deduce the away paths by examining all the changes.

    $away = array();
    foreach ($changes as $change) {
      if ($change->getTargetPath()) {
        $away[$change->getTargetPath()][] = $change->getPath();
      }
    }
    foreach ($changes as $change) {
      if (isset($away[$change->getPath()])) {
        $change->setAwayPaths($away[$change->getPath()]);
      }
    }

    return $changes;
  }

}
