<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Judge;

use Axitdev\PhpUnitAiEvals\Support\Payload;
use Psr\Http\Client\ClientInterface;
use UnexpectedValueException;

/**
 * Judge backed by a System One model (TypeSafe Jev) at `POST {baseUri}/systemone`.
 *
 * System One models answer atomic questions with calibrated probabilities instead of writing a grade, so a request is
 * split into yes/no ("Noul") questions sent together in one call:
 * - a rubric asks one question per criterion: does the output satisfy it?
 * - grounding asks one question per sentence of the output: is it supported by the context?
 *
 * The score is the lowest probability, so every criterion or sentence has to pass. The model gives no explanation;
 * the reason lists the weakest answers with their probabilities and the model release that gave them.
 */
final class SystemOneJudgeClient implements JudgeClientInterface
{
    private const string CLAIM_QUESTION = 'Is `claim`, a sentence taken from `answer`, stated in or directly supported by `context`?';

    private const int REASON_ITEMS = 3;

    private const int REASON_LABEL_WIDTH = 120;

    private readonly JudgeHttp $http;

    public function __construct(
        ClientInterface $http,
        private readonly JudgeSettings $settings,
    ) {
        $this->http = new JudgeHttp($http);
    }

    /**
     * Sentences of the output, one per question; list markers and lines without words are dropped.
     *
     * @return list<string>
     */
    public static function claims(string $output): array
    {
        $claims = [];
        foreach (preg_split('/\R+/u', $output) ?: [] as $line) {
            $line = (string) preg_replace('/^\s*(?:[-*•>]+|\d+[.)])\s*/u', '', $line);
            foreach (preg_split('/(?<=[.!?])\s+(?=\S)/u', $line) ?: [] as $sentence) {
                $sentence = trim($sentence);
                if (preg_match('/[\p{L}\p{N}]/u', $sentence) === 1) {
                    $claims[] = $sentence;
                }
            }
        }

        return $claims;
    }

    /**
     * The exact request body, so a changed criterion, context or output is a cassette miss rather than a stale verdict.
     */
    public function cachePayload(JudgeRequest $request): array
    {
        if ($request->kind === JudgeRequest::GROUNDING) {
            $state = ['context' => $request->context, 'answer' => $request->output];
            $questions = [];
            foreach (self::claims($request->output) as $i => $claim) {
                $questions['claim_' . ($i + 1)] = [
                    'type' => 'noul',
                    'instructions' => ['claim' => $claim, 'question' => self::CLAIM_QUESTION],
                ];
            }
        } else {
            $state = $request->output;
            $questions = [];
            foreach ($request->criteria as $i => $criterion) {
                $questions['criterion_' . ($i + 1)] = ['type' => 'noul', 'instructions' => $criterion];
            }
        }

        return ['model' => $this->settings->model, 'state' => $state, 'questions' => $questions];
    }

    public function judge(JudgeRequest $request): JudgeVerdict
    {
        $payload = $this->cachePayload($request);
        $questions = Payload::map($payload['questions']);
        if ($questions === []) {
            return new JudgeVerdict(1.0, 'The output makes no claims to check.');
        }

        $body = $this->http->postJson(rtrim($this->settings->baseUri, '/') . '/systemone', $this->settings->apiKey, $payload);
        $response = Payload::map(json_decode($body, true));

        $answers = [];
        foreach ($questions as $id => $question) {
            $value = Payload::get($response, 'answers', $id, 'noul');
            if ((!is_int($value) && !is_float($value)) || $value < 0 || $value > 1) {
                throw new UnexpectedValueException(sprintf('System One answer has no probability between 0 and 1 for "%s": %s', $id, mb_substr($body, 0, 300)));
            }
            $label = Payload::get($question, 'instructions', 'claim') ?? Payload::get($question, 'instructions');
            $answers[] = [Payload::string($label), (float) $value];
        }
        // Stable, so equal probabilities keep the order they were asked in.
        usort($answers, static fn(array $a, array $b): int => $a[1] <=> $b[1]);

        return new JudgeVerdict($answers[0][1], self::reason($answers, Payload::string($response['model'] ?? null)));
    }

    /**
     * @param non-empty-list<array{string, float}> $answers label and P(yes), lowest first
     */
    private static function reason(array $answers, string $release): string
    {
        $items = [];
        foreach (array_slice($answers, 0, self::REASON_ITEMS) as [$label, $p]) {
            $items[] = sprintf('"%s" %.2f', mb_strimwidth($label, 0, self::REASON_LABEL_WIDTH, '…'), $p);
        }
        $more = count($answers) - count($items);

        return sprintf(
            'P(yes) per question, lowest first: %s%s%s',
            implode('; ', $items),
            $more > 0 ? sprintf('; %d more', $more) : '',
            $release !== '' ? " [$release]" : '',
        );
    }
}
