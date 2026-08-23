class CrmScoreCalculator:
    @staticmethod
    def calculate(calc_key: str, raw: dict, max_points: float, dealership: dict = None) -> float:
        """
        Calculates CRM score points obtained based on the calc_key.
        Returns float or None.
        """
        if not calc_key:
            return None
        if dealership is None:
            dealership = {}

        key = calc_key.strip().lower()
        if key == 'detailing_of_enquiry':
            return CrmScoreCalculator.detailing_of_enquiry(raw, max_points)
        elif key == 'timely_followup':
            return CrmScoreCalculator.timed_response_bands(raw, max_points)
        elif key == 'number_of_followups':
            return CrmScoreCalculator.number_of_followups(raw, max_points)
        elif key == 'voip_calling':
            return CrmScoreCalculator.voip_calling(raw, max_points)
        elif key == 'first_response_time':
            return CrmScoreCalculator.timed_response_bands(raw, max_points)
        elif key == 'manager_assigning_time':
            return CrmScoreCalculator.manager_assigning_time_bands(raw, max_points)
        elif key == 'digital_enquiry_targets':
            return CrmScoreCalculator.digital_enquiry_targets(raw, max_points, dealership)
        elif key == 'stage_won_conversion':
            return CrmScoreCalculator.stage_won_conversion(raw, max_points, dealership)
        elif key == 'fronx_test_drive_monthly':
            return CrmScoreCalculator.fronx_test_drive_monthly(raw)
        elif key == 'pipeline_tracking':
            return CrmScoreCalculator.pipeline_tracking(raw, max_points)
        else:
            return None

    @staticmethod
    def pipeline_tracking(raw: dict, max_points: float) -> float:
        max_days = CrmScoreCalculator.find_raw_value(raw, 'business days')
        if max_days is None:
            return None

        if max_days <= 1:
            fraction = 1.0
        elif max_days <= 3:
            fraction = 0.6667
        else:
            fraction = 0.0

        return round(fraction * max_points, 2)

    @staticmethod
    def fronx_test_drive_monthly(raw: dict) -> float:
        completed = (
            CrmScoreCalculator.find_raw_value(raw, 'completed')
            or CrmScoreCalculator.find_raw_value(raw, 'achiev')
            or CrmScoreCalculator.find_raw_value(raw, 'actual')
        )
        if completed is None:
            return None

        return round((completed / 104) * 100, 2)

    @staticmethod
    def digital_enquiry_targets(raw: dict, max_points: float, dealership: dict) -> float:
        target = float(dealership.get('digital_enquiry_target') or 0.0)
        if target <= 0:
            return None

        achieved = (
            CrmScoreCalculator.find_raw_value(raw, 'digital')
            or CrmScoreCalculator.find_raw_value(raw, 'achiev')
            or CrmScoreCalculator.find_raw_value(raw, 'actual')
            or CrmScoreCalculator.find_first_raw_value(raw, ['row count'])
        )
        if achieved is None:
            return None

        return CrmScoreCalculator.achievement_bands(achieved, target, max_points)

    @staticmethod
    def stage_won_conversion(raw: dict, max_points: float, dealership: dict) -> float:
        target = float(dealership.get('digital_enquiry_conversion_target') or 0.0)
        if target <= 0:
            return None

        achieved = (
            CrmScoreCalculator.find_raw_value(raw, 'won')
            or CrmScoreCalculator.find_raw_value(raw, 'achiev')
            or CrmScoreCalculator.find_raw_value(raw, 'actual')
        )
        if achieved is None:
            return None

        return round((achieved / target) * 100, 2)

    @staticmethod
    def achievement_bands(achieved: float, target: float, max_points: float) -> float:
        percentage = (achieved / target) * 100
        if percentage > 100:
            fraction = 1.0
        elif percentage >= 90:
            fraction = 0.8333
        elif percentage >= 80:
            fraction = 0.6667
        elif percentage >= 70:
            fraction = 0.5
        elif percentage >= 60:
            fraction = 0.3333
        else:
            fraction = 0.0

        return round(fraction * max_points, 2)

    @staticmethod
    def number_of_followups(raw: dict, max_points: float) -> float:
        enquiries = CrmScoreCalculator.find_raw_value_all(raw, ['total', 'enquir'])
        follow_ups = CrmScoreCalculator.find_raw_value_all(raw, ['total', 'follow'])
        if enquiries is None or follow_ups is None or enquiries <= 0:
            return None

        percentage = (follow_ups / enquiries) * 100
        if percentage > 250:
            fraction = 1.0
        elif percentage >= 200:
            fraction = 0.75
        elif percentage >= 170:
            fraction = 0.6
        elif percentage >= 150:
            fraction = 0.45
        elif percentage >= 96:
            fraction = 0.3
        elif percentage >= 50:
            fraction = 0.15
        else:
            fraction = 0.0

        return round(fraction * max_points, 2)

    @staticmethod
    def voip_calling(raw: dict, max_points: float) -> float:
        voip_calls = CrmScoreCalculator.find_raw_value_all(raw, ['total', 'voip'])
        follow_ups = CrmScoreCalculator.find_raw_value_all(raw, ['total', 'follow'])
        if voip_calls is None or follow_ups is None or follow_ups <= 0:
            return None

        percentage = (voip_calls / follow_ups) * 100
        if percentage > 90:
            fraction = 1.0
        elif percentage >= 85:
            fraction = 0.8
        elif percentage >= 80:
            fraction = 0.6
        elif percentage >= 75:
            fraction = 0.4
        else:
            fraction = 0.0

        return round(fraction * max_points, 2)

    @staticmethod
    def detailing_of_enquiry(raw: dict, max_points: float) -> float:
        filled = CrmScoreCalculator.find_raw_value(raw, 'fill')
        view = CrmScoreCalculator.find_raw_value(raw, 'view')
        if filled is None or view is None or view <= 0:
            return None

        percentage = min(100.0, (filled / view) * 100.0)
        return round((percentage / 100.0) * max_points, 2)

    @staticmethod
    def timed_response_bands(raw: dict, max_points: float) -> float:
        avg_minutes = CrmScoreCalculator.find_raw_value(raw, 'min')
        if avg_minutes is None:
            return None

        if avg_minutes <= 20:
            fraction = 1.0
        elif avg_minutes <= 40:
            fraction = 0.75
        elif avg_minutes <= 60:
            fraction = 0.5
        elif avg_minutes <= 80:
            fraction = 0.25
        else:
            fraction = 0.0

        return round(fraction * max_points, 2)

    @staticmethod
    def manager_assigning_time_bands(raw: dict, max_points: float) -> float:
        avg_minutes = CrmScoreCalculator.find_raw_value(raw, 'min')
        if avg_minutes is None:
            return None

        if avg_minutes <= 20:
            fraction = 1.0
        elif avg_minutes <= 40:
            fraction = 0.75
        elif avg_minutes <= 60:
            fraction = 0.5
        else:
            fraction = 0.25

        return round(fraction * max_points, 2)

    @staticmethod
    def find_raw_value(raw: dict, needle: str) -> float:
        needle_lower = needle.lower()
        for key, value in raw.items():
            if needle_lower in str(key).lower():
                try:
                    return float(value)
                except (ValueError, TypeError):
                    pass
        return None

    @staticmethod
    def find_first_raw_value(raw: dict, exclude_keys_lower: list) -> float:
        for key, value in raw.items():
            if str(key).lower() in exclude_keys_lower:
                continue
            try:
                return float(value)
            except (ValueError, TypeError):
                pass
        return None

    @staticmethod
    def find_raw_value_all(raw: dict, must_contain_all: list) -> float:
        for key, value in raw.items():
            key_lower = str(key).lower()
            if all(needle.lower() in key_lower for needle in must_contain_all):
                try:
                    return float(value)
                except (ValueError, TypeError):
                    pass
        return None
