<?php

declare(strict_types=1);

namespace HalalPulse\Multibagger;

use InvalidArgumentException;
use PDO;
use Throwable;

final readonly class MultibaggerRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function activeMethodology(): ?MultibaggerMethodology
    {
        $row = $this->pdo->query('SELECT * FROM multibagger_methodologies WHERE is_active = 1 ORDER BY activated_at DESC, id DESC LIMIT 1')->fetch();
        return is_array($row) ? MultibaggerMethodology::fromDatabase($row) : null;
    }

    /** @return array{companies: int, scored: int, alert_eligible: int, insufficient: int} */
    public function summary(): array
    {
        $row = $this->pdo->query(
            <<<'SQL'
            SELECT
                (SELECT COUNT(*) FROM companies WHERE is_active = 1) AS companies,
                (SELECT COUNT(*) FROM multibagger_scores ms WHERE ms.status = 'scored' AND ms.id = (SELECT MAX(ms2.id) FROM multibagger_scores ms2 WHERE ms2.company_id = ms.company_id)) AS scored,
                (SELECT COUNT(*) FROM multibagger_scores ms WHERE ms.alert_eligible = 1 AND ms.id = (SELECT MAX(ms2.id) FROM multibagger_scores ms2 WHERE ms2.company_id = ms.company_id)) AS alert_eligible,
                (SELECT COUNT(*) FROM multibagger_scores ms WHERE ms.status = 'insufficient' AND ms.id = (SELECT MAX(ms2.id) FROM multibagger_scores ms2 WHERE ms2.company_id = ms.company_id)) AS insufficient
            SQL
        )->fetch();
        return ['companies'=>(int)($row['companies']??0),'scored'=>(int)($row['scored']??0),'alert_eligible'=>(int)($row['alert_eligible']??0),'insufficient'=>(int)($row['insufficient']??0)];
    }

    /** @return list<array<string, mixed>> */
    public function companies(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        return $this->pdo->query(
            <<<'SQL'
            SELECT c.id, c.exchange, c.symbol, c.company_name, c.sector,
                (SELECT ss.status FROM sharia_screenings ss INNER JOIN sharia_policies sp ON sp.id=ss.policy_id AND sp.is_active=1 WHERE ss.company_id=c.id ORDER BY ss.id DESC LIMIT 1) AS sharia_status,
                (SELECT ms.status FROM multibagger_scores ms WHERE ms.company_id=c.id ORDER BY ms.id DESC LIMIT 1) AS score_status,
                (SELECT ms.final_score FROM multibagger_scores ms WHERE ms.company_id=c.id ORDER BY ms.id DESC LIMIT 1) AS final_score,
                (SELECT ms.market_cap_category FROM multibagger_scores ms WHERE ms.company_id=c.id ORDER BY ms.id DESC LIMIT 1) AS market_cap_category,
                (SELECT ms.alert_eligible FROM multibagger_scores ms WHERE ms.company_id=c.id ORDER BY ms.id DESC LIMIT 1) AS alert_eligible,
                (SELECT ms.period_end FROM multibagger_scores ms WHERE ms.company_id=c.id ORDER BY ms.id DESC LIMIT 1) AS score_period
            FROM companies c WHERE c.is_active=1
            ORDER BY c.company_name, c.exchange, c.symbol
            LIMIT
            SQL . ' ' . $limit
        )->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function company(int $companyId): ?array
    {
        $statement=$this->pdo->prepare('SELECT * FROM companies WHERE id=:id LIMIT 1');$statement->execute(['id'=>$companyId]);$row=$statement->fetch();return is_array($row)?$row:null;
    }

    /** @return array<string, mixed>|null */
    public function currentShariaPass(int $companyId, string $periodEnd): ?array
    {
        $statement=$this->pdo->prepare(
            <<<'SQL'
            SELECT ss.* FROM sharia_screenings ss
            INNER JOIN sharia_policies sp ON sp.id=ss.policy_id AND sp.is_active=1
            WHERE ss.company_id=:company_id AND ss.period_end=:period_end AND ss.status='passed'
            ORDER BY ss.id DESC LIMIT 1
            SQL
        );
        $statement->execute(['company_id'=>$companyId,'period_end'=>$periodEnd]);$row=$statement->fetch();return is_array($row)?$row:null;
    }

    /** @return array<string, array<string, mixed>> */
    public function factorReviews(int $companyId, string $periodEnd): array
    {
        $statement=$this->pdo->prepare(
            <<<'SQL'
            SELECT mfr.*,u.display_name AS reviewer_name,
                gtr.review_status AS government_review_status,gtr.impact AS government_review_impact,gtr.sector AS government_review_sector,
                ga.title AS government_announcement_title,ga.source AS government_source,ga.official_url AS government_official_url
            FROM multibagger_factor_reviews mfr
            INNER JOIN users u ON u.id=mfr.reviewed_by_user_id
            LEFT JOIN government_tailwind_reviews gtr ON gtr.id=mfr.government_tailwind_review_id
            LEFT JOIN government_announcements ga ON ga.id=gtr.announcement_id
            WHERE mfr.company_id=:company_id AND mfr.period_end=:period_end AND mfr.review_status='current'
            ORDER BY mfr.factor_key
            SQL
        );$statement->execute(['company_id'=>$companyId,'period_end'=>$periodEnd]);$rows=[];foreach($statement->fetchAll() as $row)$rows[(string)$row['factor_key']]=$row;return $rows;
    }

    public function saveFactorReview(int $companyId,string $periodEnd,string $factorKey,int $grade,string $evidenceNote,?string $sourceUrl,?int $documentId,?int $governmentTailwindReviewId,int $userId): void
    {
        $this->replaceCurrent('multibagger_factor_reviews',$companyId,$periodEnd,'factor_key',$factorKey,function()use($companyId,$periodEnd,$factorKey,$grade,$evidenceNote,$sourceUrl,$documentId,$governmentTailwindReviewId,$userId):void{
            $s=$this->pdo->prepare(
                <<<'SQL'
                INSERT INTO multibagger_factor_reviews(company_id,period_end,factor_key,grade,evidence_note,evidence_source_url,source_document_id,government_tailwind_review_id,review_status,reviewed_by_user_id,reviewed_at)
                VALUES(:company_id,:period_end,:factor_key,:grade,:evidence_note,:evidence_source_url,:source_document_id,:government_tailwind_review_id,'current',:user_id,CURRENT_TIMESTAMP)
                SQL
            );$s->execute(['company_id'=>$companyId,'period_end'=>$periodEnd,'factor_key'=>$factorKey,'grade'=>$grade,'evidence_note'=>$evidenceNote,'evidence_source_url'=>$sourceUrl,'source_document_id'=>$documentId,'government_tailwind_review_id'=>$governmentTailwindReviewId,'user_id'=>$userId]);
        });
    }

    /** @return array<string, mixed>|null */
    public function valuationReview(int $companyId,string $periodEnd): ?array
    {
        $s=$this->pdo->prepare("SELECT * FROM multibagger_valuation_reviews WHERE company_id=:company_id AND period_end=:period_end AND review_status='current' ORDER BY id DESC LIMIT 1");$s->execute(['company_id'=>$companyId,'period_end'=>$periodEnd]);$r=$s->fetch();return is_array($r)?$r:null;
    }

    /** @param array<string, string|int|null> $values */
    public function saveValuationReview(int $companyId,string $periodEnd,array $values,int $userId):void
    {
        $this->replaceCurrent('multibagger_valuation_reviews',$companyId,$periodEnd,null,null,function()use($companyId,$periodEnd,$values,$userId):void{
            $s=$this->pdo->prepare(
                <<<'SQL'
                INSERT INTO multibagger_valuation_reviews(company_id,period_end,currency,eps,book_value_per_share,dcf_value_per_share,current_price,dcf_assumptions_note,evidence_note,evidence_source_url,source_document_id,review_status,reviewed_by_user_id,reviewed_at)
                VALUES(:company_id,:period_end,:currency,:eps,:book_value_per_share,:dcf_value_per_share,:current_price,:dcf_assumptions_note,:evidence_note,:evidence_source_url,:source_document_id,'current',:user_id,CURRENT_TIMESTAMP)
                SQL
            );$s->execute(['company_id'=>$companyId,'period_end'=>$periodEnd,'currency'=>$values['currency'],'eps'=>$values['eps'],'book_value_per_share'=>$values['book_value_per_share'],'dcf_value_per_share'=>$values['dcf_value_per_share'],'current_price'=>$values['current_price'],'dcf_assumptions_note'=>$values['dcf_assumptions_note'],'evidence_note'=>$values['evidence_note'],'evidence_source_url'=>$values['evidence_source_url'],'source_document_id'=>$values['source_document_id'],'user_id'=>$userId]);
        });
    }

    /** @return array<string, mixed>|null */
    public function riskReview(int $companyId,string $periodEnd):?array
    {
        $s=$this->pdo->prepare("SELECT * FROM multibagger_risk_reviews WHERE company_id=:company_id AND period_end=:period_end AND review_status='current' ORDER BY id DESC LIMIT 1");$s->execute(['company_id'=>$companyId,'period_end'=>$periodEnd]);$r=$s->fetch();return is_array($r)?$r:null;
    }

    /** @param list<string> $redFlags @param list<string> $greenFlags */
    public function saveRiskReview(int $companyId,string $periodEnd,string $marketCap,array $redFlags,array $greenFlags,string $evidenceNote,string $evidenceSourceUrl,int $userId):void
    {
        $this->replaceCurrent('multibagger_risk_reviews',$companyId,$periodEnd,null,null,function()use($companyId,$periodEnd,$marketCap,$redFlags,$greenFlags,$evidenceNote,$evidenceSourceUrl,$userId):void{
            $s=$this->pdo->prepare(
                <<<'SQL'
                INSERT INTO multibagger_risk_reviews(company_id,period_end,market_cap_crore,red_flags,green_flags,evidence_note,evidence_source_url,review_status,reviewed_by_user_id,reviewed_at)
                VALUES(:company_id,:period_end,:market_cap_crore,:red_flags,:green_flags,:evidence_note,:evidence_source_url,'current',:user_id,CURRENT_TIMESTAMP)
                SQL
            );$s->execute(['company_id'=>$companyId,'period_end'=>$periodEnd,'market_cap_crore'=>$marketCap,'red_flags'=>json_encode($redFlags,JSON_THROW_ON_ERROR),'green_flags'=>json_encode($greenFlags,JSON_THROW_ON_ERROR),'evidence_note'=>$evidenceNote,'evidence_source_url'=>$evidenceSourceUrl,'user_id'=>$userId]);
        });
    }

    public function documentBelongsToCompany(int $documentId,int $companyId):bool
    {
        $s=$this->pdo->prepare("SELECT COUNT(*) FROM filing_documents fd INNER JOIN filings f ON f.id=fd.filing_id WHERE fd.id=:document_id AND f.company_id=:company_id AND fd.download_status='downloaded'");$s->execute(['document_id'=>$documentId,'company_id'=>$companyId]);return(int)$s->fetchColumn()===1;
    }

    /** @return list<array<string, mixed>> */
    public function documentsForCompany(int $companyId):array
    {
        $s=$this->pdo->prepare("SELECT fd.id,f.announced_at,f.subject FROM filing_documents fd INNER JOIN filings f ON f.id=fd.filing_id WHERE f.company_id=:company_id AND fd.download_status='downloaded' ORDER BY f.announced_at DESC LIMIT 50");$s->execute(['company_id'=>$companyId]);return$s->fetchAll();
    }

    public function recordScore(int $companyId,MultibaggerMethodology $methodology,int $shariaScreeningId,string $periodEnd,MultibaggerScoringResult $result,int $userId):int
    {
        $s=$this->pdo->prepare(
            <<<'SQL'
            INSERT INTO multibagger_scores(company_id,methodology_id,sharia_screening_id,period_end,status,final_score,weighted_score,market_cap_category,undervalued_by_both,alert_eligible,factor_results,reasons,valuation_snapshot,risk_snapshot,computed_by_user_id,computed_at)
            VALUES(:company_id,:methodology_id,:sharia_screening_id,:period_end,:status,:final_score,:weighted_score,:market_cap_category,:undervalued_by_both,:alert_eligible,:factor_results,:reasons,:valuation_snapshot,:risk_snapshot,:user_id,CURRENT_TIMESTAMP)
            SQL
        );$s->execute(['company_id'=>$companyId,'methodology_id'=>$methodology->id,'sharia_screening_id'=>$shariaScreeningId,'period_end'=>$periodEnd,'status'=>$result->status,'final_score'=>$result->finalScore,'weighted_score'=>$result->weightedScore,'market_cap_category'=>$result->marketCapCategory,'undervalued_by_both'=>$result->undervaluedByBoth?1:0,'alert_eligible'=>$result->alertEligible?1:0,'factor_results'=>json_encode($result->factorResults,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),'reasons'=>json_encode($result->reasons,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),'valuation_snapshot'=>json_encode($result->valuationSnapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),'risk_snapshot'=>json_encode($result->riskSnapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),'user_id'=>$userId]);return(int)$this->pdo->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function scoreHistory(int $companyId):array
    {
        $s=$this->pdo->prepare("SELECT ms.*,mm.version AS methodology_version,u.display_name AS computed_by_name FROM multibagger_scores ms INNER JOIN multibagger_methodologies mm ON mm.id=ms.methodology_id INNER JOIN users u ON u.id=ms.computed_by_user_id WHERE ms.company_id=:company_id ORDER BY ms.id DESC LIMIT 20");$s->execute(['company_id'=>$companyId]);return$s->fetchAll();
    }

    private function replaceCurrent(string $table,int $companyId,string $periodEnd,?string $keyColumn,?string $keyValue,callable $insert):void
    {
        $allowed=['multibagger_factor_reviews','multibagger_valuation_reviews','multibagger_risk_reviews'];if(!in_array($table,$allowed,true))throw new InvalidArgumentException('Review table is invalid.');
        $this->pdo->beginTransaction();try{$lock=$this->pdo->prepare('SELECT id FROM companies WHERE id=:id FOR UPDATE');$lock->execute(['id'=>$companyId]);if($lock->fetchColumn()===false)throw new InvalidArgumentException('Company not found.');
            $sql="UPDATE {$table} SET review_status='superseded' WHERE company_id=:company_id AND period_end=:period_end AND review_status='current'";$params=['company_id'=>$companyId,'period_end'=>$periodEnd];if($keyColumn!==null){$sql.=" AND {$keyColumn}=:key_value";$params['key_value']=$keyValue;}$s=$this->pdo->prepare($sql);$s->execute($params);$insert();$this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }
}
