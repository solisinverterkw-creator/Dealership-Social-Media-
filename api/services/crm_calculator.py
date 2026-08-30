class CrmScoreCalculator:
    @staticmethod
    def calculate(calc_key: str, raw: dict, max_points: float, dealership: dict = None) -> float:
        """
        Calculates CRM score points obtained based on the calc_key and imported raw data.
        Returns float.
        """
        if not raw:
            return 0.0
        if dealership is None:
            dealership = {}

        key = str(calc_key or '').strip().lower()

        if key in ('detailing_of_enquiry', 'detailing'):
            return CrmScoreCalculator.detailing_of_enquiry(raw, max_points)
        elif key in ('timely_followup', 'timely_follow_up'):
            return CrmScoreCalculator.timed_response_bands(raw, max_points)
        elif key in ('number_of_followups', 'number_of_follow_ups'):
            return CrmScoreCalculator.number_of_followups(raw, max_points)
        elif key in ('voip_calling', 'voip'):
            return CrmScoreCalculator.voip_calling(raw, max_points)
        elif key in ('first_response_time', 'salesperson_first_response'):
            return CrmScoreCalculator.timed_response_bands(raw, max_points)
        elif key in ('manager_assigning_time', 'sales_manager_assigning'):
            return CrmScoreCalculator.manager_assigning_time_bands(raw, max_points)
        elif key in ('digital_enquiry_targets', 'digital_targets'):
            return CrmScoreCalculator.digital_enquiry_targets(raw, max_points, dealership)
        elif key in ('stage_won_conversion', 'digital_conversion'):
            return CrmScoreCalculator.stage_won_conversion(raw, max_points, dealership)
        elif key in ('fronx_test_drive_monthly', 'fronx_test_drive'):
            return CrmScoreCalculator.fronx_test_drive_monthly(raw, max_points)
        elif key in ('pipeline_tracking', 'pipeline'):
            return CrmScoreCalculator.pipeline_tracking(raw, max_points)
        elif key in ('sales_crm_registration', 'crm_registration'):
            return CrmScoreCalculator.sales_crm_registration(raw, max_points)
        elif key in ('evaluation_feedback_accuracy', 'evaluation_accuracy'):
            return CrmScoreCalculator.evaluation_feedback_accuracy(raw, max_points)
        else:
            # Fallback for dynamic parameters
            return CrmScoreCalculator.generic_fallback_score(raw, max_points)

    @staticmethod
    def sales_crm_registration(raw: dict, max_points: float) -> float:
        val = CrmScoreCalculator.extract_numeric_value(raw, ['register', 'active', 'unregister', 'row count'])
        if val is None:
            return 0.0
        # Criteria: Negative: unregistered/inactive -> If unregister/inactive count > 0 -> deduction
        if 'unregistered' in str(raw).lower() or 'inactive' in str(raw).lower():
            return -abs(max_points) if val > 0 else 0.0
        return max_points if val >= 100 else round((val / 100.0) * max_points, 2)

    @staticmethod
    def detailing_of_enquiry(raw: dict, max_points: float) -> float:
        filled = CrmScoreCalculator.extract_numeric_value(raw, ['total fields filled', 'fields filled', 'filled'])
        in_view = CrmScoreCalculator.extract_numeric_value(raw, ['total fields in view', 'fields in view', 'view'])

        if filled is not None and in_view is not None and in_view > 0:
            pct = (filled / in_view) * 100.0
        else:
            val = CrmScoreCalculator.extract_numeric_value(raw, ['detail', 'complete', 'field', 'fill'])
            if val is None:
                val = CrmScoreCalculator.extract_any_numeric(raw)
            if val is None:
                return 0.0
            pct = float(val)

        pct = min(100.0, max(0.0, pct))
        return round((pct / 100.0) * max_points, 2)

    @staticmethod
    def timed_response_bands(raw: dict, max_points: float) -> float:
        # Prefer exact key containing '(min)' or numeric response time minutes sum
        time_sum = None
        for k, v in raw.items():
            k_lower = str(k).lower().strip()
            if 'response time (min)' in k_lower or 'response time min' in k_lower:
                try:
                    time_sum = float(v)
                    break
                except Exception:
                    pass

        if time_sum is None:
            time_sum = CrmScoreCalculator.extract_numeric_value(raw, ['sales person response time (min)', 'sales person response time', 'response time', 'min'])

        count = float(raw.get('Row Count') or 1.0)
        avg_minutes = (time_sum / count) if (time_sum is not None and count > 0) else 0.0

        if avg_minutes <= 20:
            fraction = 1.0
        elif avg_minutes <= 40:
            fraction = 0.75
        elif avg_minutes <= 60:
            fraction = 0.50
        elif avg_minutes <= 80:
            fraction = 0.25
        else:
            fraction = 0.0

        return round(fraction * max_points, 2)

    @staticmethod
    def manager_assigning_time_bands(raw: dict, max_points: float) -> float:
        enquiries = CrmScoreCalculator.extract_numeric_value(raw, ['enquir', 'total enq', 'total enquiries'])
        if enquiries is None or enquiries <= 0:
            enquiries = float(raw.get('Row Count') or 1.0)

        avg_minutes = raw.get('Average Response Time (min)')
        if avg_minutes is None:
            time_sum = CrmScoreCalculator.extract_numeric_value(raw, ['assign', 'min', 'time'])
            if time_sum is not None and enquiries > 0:
                avg_minutes = time_sum / enquiries if time_sum > 120.0 else time_sum
            else:
                avg_minutes = CrmScoreCalculator.extract_any_numeric(raw)

        if avg_minutes is None:
            return 0.0

        if avg_minutes <= 20:
            fraction = 1.0
        elif avg_minutes <= 40:
            fraction = 0.75
        elif avg_minutes <= 60:
            fraction = 0.50
        else:
            fraction = 0.25

        return round(fraction * max_points, 2)

    @staticmethod
    def number_of_followups(raw: dict, max_points: float) -> float:
        follow_ups = CrmScoreCalculator.extract_numeric_value(raw, ['follow'])
        enquiries = CrmScoreCalculator.extract_numeric_value(raw, ['enquir', 'total', 'row count'])
        
        if follow_ups is not None and enquiries is not None and enquiries > 0:
            percentage = (follow_ups / enquiries) * 100.0
        else:
            pct_val = CrmScoreCalculator.extract_any_numeric(raw)
            percentage = pct_val if pct_val is not None else 0.0

        if percentage >= 250:
            fraction = 1.0
        elif percentage >= 200:
            fraction = 0.75
        elif percentage >= 170:
            fraction = 0.60
        elif percentage >= 150:
            fraction = 0.45
        elif percentage >= 100:
            fraction = 0.30
        elif percentage > 0:
            fraction = round(min(1.0, max(0.25, percentage / 20.0)), 4)
        else:
            fraction = 0.0

        return round(fraction * max_points, 2)

    @staticmethod
    def voip_calling(raw: dict, max_points: float) -> float:
        voip = CrmScoreCalculator.extract_numeric_value(raw, ['voip', 'call'])
        if voip is None:
            pct_val = CrmScoreCalculator.extract_any_numeric(raw)
            percentage = pct_val if pct_val is not None else 0.0
        else:
            follow_ups = CrmScoreCalculator.extract_numeric_value(raw, ['follow', 'total']) or 1.0
            percentage = (voip / follow_ups) * 100.0 if follow_ups > 0 else voip

        if percentage >= 90:
            fraction = 1.0
        elif percentage >= 85:
            fraction = 0.80
        elif percentage >= 80:
            fraction = 0.60
        elif percentage >= 75:
            fraction = 0.40
        else:
            fraction = 0.0

        return round(fraction * max_points, 2)

    @staticmethod
    def digital_enquiry_targets(raw: dict, max_points: float, dealership: dict) -> float:
        target = float(dealership.get('digital_enquiry_target') or 100.0)
        achieved = CrmScoreCalculator.extract_numeric_value(raw, ['digital', 'achiev', 'actual', 'row count'])
        if achieved is None:
            achieved = CrmScoreCalculator.extract_any_numeric(raw) or 0.0

        percentage = (achieved / target) * 100.0 if target > 0 else achieved

        if percentage >= 100:
            fraction = 1.0
        elif percentage >= 90:
            fraction = 0.8333
        elif percentage >= 80:
            fraction = 0.6667
        elif percentage >= 70:
            fraction = 0.50
        elif percentage >= 60:
            fraction = 0.3333
        else:
            fraction = 0.0

        return round(fraction * max_points, 2)

    @staticmethod
    def stage_won_conversion(raw: dict, max_points: float, dealership: dict) -> float:
        target = float(dealership.get('digital_enquiry_conversion_target') or 100.0)
        achieved = CrmScoreCalculator.extract_numeric_value(raw, ['won', 'conversion', 'achiev', 'actual'])
        if achieved is None:
            achieved = CrmScoreCalculator.extract_any_numeric(raw) or 0.0

        percentage = (achieved / target) * 100.0 if target > 0 else achieved
        return round(min(1.0, percentage / 100.0) * max_points, 2)

    @staticmethod
    def fronx_test_drive_monthly(raw: dict, max_points: float) -> float:
        completed = CrmScoreCalculator.extract_numeric_value(raw, ['test', 'drive', 'fronx', 'completed', 'actual'])
        if completed is None:
            completed = CrmScoreCalculator.extract_any_numeric(raw) or 0.0
        return round(min(1.0, completed / 100.0) * max_points, 2)

    @staticmethod
    def pipeline_tracking(raw: dict, max_points: float) -> float:
        max_days = CrmScoreCalculator.extract_numeric_value(raw, ['business days', 'days', 'pipeline'])
        if max_days is None:
            max_days = CrmScoreCalculator.extract_any_numeric(raw) or 0.0

        if max_days <= 1:
            fraction = 1.0
        elif max_days <= 3:
            fraction = 0.6667
        else:
            fraction = 0.0

        return round(fraction * max_points, 2)

    @staticmethod
    def evaluation_feedback_accuracy(raw: dict, max_points: float) -> float:
        pct = CrmScoreCalculator.extract_numeric_value(raw, ['evaluat', 'feedback', 'accurac', 'accur'])
        if pct is None:
            pct = CrmScoreCalculator.extract_any_numeric(raw) or 0.0

        if pct >= 90:
            fraction = 1.0
        elif pct >= 80:
            fraction = 0.80
        elif pct >= 70:
            fraction = 0.60
        else:
            fraction = 0.0

        return round(fraction * max_points, 2)

    @staticmethod
    def generic_fallback_score(raw: dict, max_points: float) -> float:
        val = CrmScoreCalculator.extract_any_numeric(raw)
        if val is None:
            return 0.0
        return round(min(1.0, val / 100.0) * max_points, 2)

    @staticmethod
    def extract_numeric_value(raw: dict, keywords: list) -> float:
        for key, value in raw.items():
            key_str = str(key).lower()
            if any(kw.lower() in key_str for kw in keywords):
                try:
                    return float(str(value).replace(',', '').strip())
                except (ValueError, TypeError):
                    pass
        return None

    @staticmethod
    def extract_any_numeric(raw: dict) -> float:
        for key, value in raw.items():
            if str(key).lower() in ('dealer', 'dealership', 'company'):
                continue
            try:
                val = float(str(value).replace(',', '').strip())
                return val
            except (ValueError, TypeError):
                pass
        return None
