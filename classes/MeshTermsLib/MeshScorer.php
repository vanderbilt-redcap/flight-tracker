<?php

namespace Vanderbilt\CareerDevLibrary\MeshTermsLib;

use Vanderbilt\CareerDevLibrary\MeshTermsLib\CollectionClasses\PosterCollection;
use Vanderbilt\CareerDevLibrary\MeshTermsLib\DataClasses\Poster;
use Vanderbilt\CareerDevLibrary\MeshTermsLib\DataClasses\Scholar;

class MeshScorer
{
	private array $meshTermNodes = [];
	private array $nodeMeshTerms = [];
	private array $posterData = [];
	private int $numSuggestions = 10;
	private array $nodeBlackList = [];
	private array $nodePenaltyCount = [];
	private int $minimumNodeDepth = 0;


	public function buildMeshTermArrays() {
		if (!file_exists(__DIR__ . '/../../temp/meshTermNodes.json')) {
			$file = __DIR__ . '/../../temp/d2025.bin';
			$handle = fopen($file, 'r');
			while (($line = fgets($handle)) !== false) {
				$line = str_replace("\n", '', $line);
				if (substr($line, 0, 3) === 'MH ') {
					$meshTerm = substr($line, 5);
					$nodes = [];
					while (($line = fgets($handle)) !== false) {
						$line = str_replace("\n", '', $line);
						if (substr($line, 0, 3) === 'MN ') {
							$nodes[] = substr($line, 5);
						} elseif (in_array(substr($line, 0, 3), ['PA ', 'MH_'])) {
							$this->meshTermNodes[$meshTerm] = $nodes;
							$this->nodeMeshTerms = array_merge($this->nodeMeshTerms, array_fill_keys($nodes, $meshTerm));
							break;
						} else {
							continue;
						}
					}
				} else {
					continue;
				}
			}
			file_put_contents(__DIR__ . '/../../temp/meshTermNodes.json', json_encode($this->meshTermNodes));
		} else {
			$this->meshTermNodes = json_decode(file_get_contents(__DIR__ . '/../../temp/meshTermNodes.json'), true);
		}
	}

	public function getMeshScore(string $MeshTerm1, $MeshTerm2): int {
		//Workaround Hack
		if ($MeshTerm1 == 'Disabled Children') {
			$MeshTerm1 = 'Children with Disabilities';
		}
		if ($MeshTerm2 == 'Disabled Children') {
			$MeshTerm2 = 'Children with Disabilities';
		}
		if (in_array($MeshTerm1, $this->nodeBlackList) || in_array($MeshTerm2, $this->nodeBlackList)) {
			return 0;
		}
		if ($MeshTerm1 === $MeshTerm2) {
			return 100;
		}
		$term1Nodes = $this->meshTermNodes[$MeshTerm1];
		if (!isset($term1Nodes)) {
			return 0;
		}
		$term1Depth = $this->getNodeDepth($term1Nodes);
		if ($term1Depth < $this->minimumNodeDepth) {
			$this->nodeBlackList[] = $MeshTerm1;
			return 0;
		}
		$term2Nodes = $this->meshTermNodes[$MeshTerm2];
		if (!isset($term2Nodes)) {
			return 0;
		}
		$term2Depth = $this->getNodeDepth($term2Nodes);
		if ($term2Depth < $this->minimumNodeDepth) {
			$this->nodeBlackList[] = $MeshTerm2;
			return 0;
		}
		$nodeScores = [];
		foreach ($term1Nodes as $node1) {
			foreach ($term2Nodes as $node2) {
				$nodeScores[] = $this->getNodeScore($node1, $node2);
			}
		}
		if ($nodeScores === []) {
			return 0;
		}
		$returnScore = max($nodeScores);
		if ($returnScore > 0) {

			if (array_key_exists($MeshTerm1, $this->nodePenaltyCount)) {
				$this->nodePenaltyCount[$MeshTerm1]++;
				max($returnScore -= $this->nodePenaltyCount[$MeshTerm1], 0);
			} else {
				$this->nodePenaltyCount[$MeshTerm1] = 1;
			}
			if (array_key_exists($MeshTerm2, $this->nodePenaltyCount)) {
				$this->nodePenaltyCount[$MeshTerm2]++;
				max($returnScore -= $this->nodePenaltyCount[$MeshTerm1], 0);
			} else {
				$this->nodePenaltyCount[$MeshTerm2] = 1;
			}
		}
		return $returnScore;
	}

	private function getNodeScore(string $node1, string $node2): int {
		if ($this->checkNodesSiblings($node1, $node2)) {
			return 30;
		}
		if ($this->checkNodesGrandParents($node1, $node2)) {
			return 4;
		}
		if ($this->checkNodesParents($node1, $node2)) {
			return 40;
		}
		if ($this->checkNodesCousins($node1, $node2)) {
			return 3;
		}
		//if ($this->checkNodesCommonRoots($node1, $node2)) {
		//	return 1;
		//}
		return 0;
	}

	private function checkNodesSiblings(string $node1, string $node2): bool {
		if ((length($node1) > 4) && length($node2) > 4) {
			return substr($node1, 0, length($node1) - 4) === substr($node2, 0, length($node2) - 4);
		}
		return false;
	}

	private function checkNodesParents(string $node1, string $node2): bool {
		$node1ParentofNode2 = strpos($node1, $node2, 0);
		$node2ParentofNode1 = strpos($node2, $node1, 0);
		if ($node1ParentofNode2 !== false || $node2ParentofNode1 !== false) {
			return true;
		}
		return false;
	}

	private function checkNodesCousins(string $node1, string $node2): bool {
		if ((length($node1) > 8) && length($node2) > 8) {
			return substr($node1, 0, length($node1) - 7) === substr($node2, 0, length($node2) - 7);
		}

		return false;
	}

	private function checkNodesCommonRoots(string $node1, string $node2): bool {
		if ((length($node1) > 8) && length($node2) > 8) {
			return substr($node1, 0, 7) === substr($node2, 0, 7);
		}
		return false;
	}

	private function checkNodesGrandparents(string $node1, string $node2): bool {
		if ((length($node1) > 4) && length($node2) > 4) {
			return substr($node1, 0, length($node1) - 7) === substr($node2, 0, length($node2) - 7);
		}
		return false;
	}

	public function getPosterRecommendations(Scholar $scholar, PosterCollection $posterCollection): array {
		$numPrimaryTerms = count($scholar->primaryMeshTerms);
		$counter = 0;
		$recommendations = [];
		$lowestScore = 0;
		$posterScore = [];
		foreach (array_merge($scholar->primaryMeshTerms, $scholar->secondaryMeshTerms) as $meshTerm) {
			foreach ($posterCollection as $poster) {
				foreach ($poster->meshTerms as $posterMeshTerm) {
					if ($meshTerm == '' || $posterMeshTerm == '') {
						continue;
					}
					if ($counter < $numPrimaryTerms) {
						if (array_key_exists($poster->getRecordId(), $posterScore)) {
							$posterScore[$poster->getRecordId()] += $this->getMeshScore($meshTerm, $posterMeshTerm) * 3;
						} else {
							$posterScore[$poster->getRecordId()] = $this->getMeshScore($meshTerm, $posterMeshTerm) * 3;
						}
					} else {
						if (array_key_exists($poster->getRecordId(), $posterScore)) {
							$posterScore[$poster->getRecordId()] += $this->getMeshScore($meshTerm, $posterMeshTerm);
						} else {
							$posterScore[$poster->getRecordId()] = $this->getMeshScore($meshTerm, $posterMeshTerm);
						}
					}
				}
				$this->resetNodePenaltyCount();
				if (count($recommendations) < $this->numSuggestions) {
					$recommendations[] = ['poster' => $poster, 'score' => $score];
					if ($score < $lowestScore) {
						$lowestScore = $score;
					}
				} elseif ($score > $lowestScore) {
					foreach ($recommendations as $key => $recommendation) {
						if ($recommendation['score'] == $lowestScore) {
							$lowestScoreIndex = $key;
							break;
						}
					}
					if (!isset($lowestScoreIndex)) {
						$lowestScoreIndex = 0;
					}
					$recommendations[$lowestScoreIndex] = ['poster' => $poster, 'score' => $score];
					$lowestScore = min(array_column($recommendations, 'score'));
				}
				$counter++;
			}
		}
		$recommendations = [];
		$lowestScore = 0;
		foreach ($posterScore as $recordId => $score) {
			if (count($recommendations) < $this->numSuggestions) {
				$recommendations[] = ['poster' => $posterCollection->getPosterByRecordId($recordId), 'score' => $score];
				if ($score < $lowestScore) {
					$lowestScore = $score;
				}
			} elseif ($score > $lowestScore) {
				foreach ($recommendations as $key => $recommendation) {
					if ($recommendation['score'] == $lowestScore) {
						$lowestScoreIndex = $key;
						break;
					}
				}
				if (!isset($lowestScoreIndex)) {
					$lowestScoreIndex = 0;
				}
				$recommendations[$lowestScoreIndex] = ['poster' => $posterCollection->getPosterByRecordId($recordId), 'score' => $score];
				$lowestScore = min(array_column($recommendations, 'score'));
			}
		}
		foreach ($recommendations as $key => $recommendation) {
			if ($recommendation['score'] == 0) {
				unset($recommendations[$key]);
			}
		}
		$posterRecommendations = array_column($recommendations, 'poster');
		return $posterRecommendations;
	}

	public function getDebugMeshScoreForPoster(Poster $poster, Scholar $scholar): array {
		$meshScores = [];
		$totalScore = 0;
		foreach (array_merge($scholar->primaryMeshTerms, $scholar->secondaryMeshTerms) as $meshTerm) {
			foreach ($poster->meshTerms as $posterMeshTerm) {
				$score = $this->getMeshScore($meshTerm, $posterMeshTerm);
				if ($score > 0) {
					$totalScore += $score;
					$meshScores[] = ['meshTerm' => $meshTerm, 'posterMeshTerm' => $posterMeshTerm, 'score' => $score];
				}
			}
		}
		return $meshScores;
	}

	public function getNodeDepth(array $nodes): int {
		$maxDepth = 0;
		foreach ($nodes as $node) {
			$nodeDepth = substr_count($node, '.');
			$maxDepth = max($maxDepth, $nodeDepth);
		}
		return $maxDepth;
	}

	public function resetNodeBlackList(): void {
		$this->nodeBlackList = [];
	}

	public function resetNodePenaltyCount(): void {
		$this->nodePenaltyCount = [];
	}
}
