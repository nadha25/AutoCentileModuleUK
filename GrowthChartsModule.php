<?php
/**
 * Auto Centile Calculator
 * Automatically calculates and displays centiles when measurements are entered
 */

namespace YourInstitution\AutoCentileModuleUK;

use ExternalModules\AbstractExternalModule;

class AutoCentileModule extends AbstractExternalModule {

    public function redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance) {
        $targetInstruments = explode(',', $this->getProjectSetting('target_instruments'));
        $targetInstruments = array_map('trim', $targetInstruments);
        
        if (in_array($instrument, $targetInstruments)) {
            $this->injectAutoCalculator();
        }
    }

    private function injectAutoCalculator() {
        $weightField = $this->getProjectSetting('weight_field') ?: 'weight_kg';
        $heightField = $this->getProjectSetting('height_field') ?: 'height_cm';
        $dobField = $this->getProjectSetting('dob_field') ?: 'date_of_birth';
        $sexField = $this->getProjectSetting('sex_field') ?: 'sex';
        $measurementDateField = $this->getProjectSetting('measurement_date_field') ?: 'measurement_date';
        $gestationWeeksField = $this->getProjectSetting('gestation_weeks_field') ?: 'gestation_weeks';
        $gestationDaysField = $this->getProjectSetting('gestation_days_field') ?: 'gestation_days';
        
        // Field names for centile results
        $weightCentileField = $this->getProjectSetting('weight_centile_field') ?: 'weight_centile';
        $heightCentileField = $this->getProjectSetting('height_centile_field') ?: 'height_centile';
        $bmiCentileField = $this->getProjectSetting('bmi_centile_field') ?: 'bmi_centile';
        
        ?>
        <style>
            .centile-display {
                display: inline-block;
                margin-left: 10px;
                padding: 5px 10px;
                background: #e8f4f8;
                border-left: 3px solid #005eb8;
                font-size: 13px;
                color: #005eb8;
                font-weight: bold;
            }
            .centile-calculating {
                color: #666;
                font-style: italic;
            }
            .centile-error {
                color: #c00;
            }
        </style>

        <script>
            const fieldNames = {
                weight: '<?php echo $weightField; ?>',
                height: '<?php echo $heightField; ?>',
                dob: '<?php echo $dobField; ?>',
                sex: '<?php echo $sexField; ?>',
                measurementDate: '<?php echo $measurementDateField; ?>',
                gestationWeeks: '<?php echo $gestationWeeksField; ?>',
                gestationDays: '<?php echo $gestationDaysField; ?>',
                weightCentile: '<?php echo $weightCentileField; ?>',
                heightCentile: '<?php echo $heightCentileField; ?>',
                bmiCentile: '<?php echo $bmiCentileField; ?>'
            };

            let calculateTimeout;

            function getFieldValue(fieldName) {
                const field = document.querySelector(`[name="${fieldName}"]`);
                return field ? field.value : '';
            }

            function getRadioValue(fieldName) {
                const radio = document.querySelector(`input[name="${fieldName}"]:checked`);
                return radio ? radio.value : '';
            }

            function setFieldValue(fieldName, value) {
                const field = document.querySelector(`[name="${fieldName}"]`);
                if (field) {
                    field.value = value;
                    $(field).trigger('change');
                }
            }

            function showCentileNext(fieldName, text, isError = false) {
                const field = document.querySelector(`[name="${fieldName}"]`);
                if (!field) return;

                // Remove existing centile display
                const existingDisplay = field.parentElement.querySelector('.centile-display');
                if (existingDisplay) {
                    existingDisplay.remove();
                }

                // Add new display
                const display = document.createElement('span');
                display.className = 'centile-display' + (isError ? ' centile-error' : '');
                display.textContent = text;
                
                // Insert after the field's container
                const container = field.closest('td') || field.parentElement;
                container.appendChild(display);
            }

            async function autoCalculateCentiles() {
                const weight = getFieldValue(fieldNames.weight);
                const height = getFieldValue(fieldNames.height);
                const dob = getFieldValue(fieldNames.dob);
                const sex = getRadioValue(fieldNames.sex);
                const measurementDate = getFieldValue(fieldNames.measurementDate);

                // Check if we have minimum required fields
                if (!dob || !sex || !measurementDate) return;
                if (!weight && !height) return;

                // Show calculating status
                if (weight) showCentileNext(fieldNames.weight, '⏳ Calculating...', false);
                if (height) showCentileNext(fieldNames.height, '⏳ Calculating...', false);

                try {
                    const formData = {
                        birth_date: dob,
                        measurement_date: measurementDate,
                        weight: weight,
                        height: height,
                        sex: sex,
                        gestation_weeks: getFieldValue(fieldNames.gestationWeeks),
                        gestation_days: getFieldValue(fieldNames.gestationDays),
                        measurement_method: 'height'
                    };

                    const response = await fetch('<?php echo $this->getUrl('ajax/calculate_centiles.php'); ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(formData)
                    });

                    if (!response.ok) {
                        throw new Error('Calculation failed');
                    }

                    const data = await response.json();

                    if (!data.success) {
                        throw new Error(data.error || 'Unknown error');
                    }

                    const results = data.results;

                    // Display and save weight centile
                    if (results.weight && !results.weight.error) {
                        const centile = Math.round(results.weight.centile * 10) / 10;
                        const sds = Math.round(results.weight.sds * 100) / 100;
                        showCentileNext(fieldNames.weight, `${centile}th centile (SDS: ${sds})`);
                        setFieldValue(fieldNames.weightCentile, centile);
                    } else if (results.weight?.error) {
                        showCentileNext(fieldNames.weight, '❌ ' + results.weight.error, true);
                    }

                    // Display and save height centile
                    if (results.height && !results.height.error) {
                        const centile = Math.round(results.height.centile * 10) / 10;
                        const sds = Math.round(results.height.sds * 100) / 100;
                        showCentileNext(fieldNames.height, `${centile}th centile (SDS: ${sds})`);
                        setFieldValue(fieldNames.heightCentile, centile);
                    } else if (results.height?.error) {
                        showCentileNext(fieldNames.height, '❌ ' + results.height.error, true);
                    }

                    // Display and save BMI centile
                    if (results.bmi && !results.bmi.error) {
                        const centile = Math.round(results.bmi.centile * 10) / 10;
                        const sds = Math.round(results.bmi.sds * 100) / 100;
                        setFieldValue(fieldNames.bmiCentile, centile);
                        
                        // Optionally show BMI centile near BMI field if it exists
                        const bmiField = document.querySelector('[name="bmi_calculated"]') || 
                                       document.querySelector('[name="bmi"]');
                        if (bmiField) {
                            showCentileNext('bmi_calculated', `BMI: ${centile}th centile (SDS: ${sds})`);
                        }
                    }

                } catch (error) {
                    console.error('Centile calculation error:', error);
                    if (weight) showCentileNext(fieldNames.weight, '❌ Error', true);
                    if (height) showCentileNext(fieldNames.height, '❌ Error', true);
                }
            }

            function scheduleCalculation() {
                clearTimeout(calculateTimeout);
                calculateTimeout = setTimeout(autoCalculateCentiles, 1000);
            }

            $(document).ready(function() {
                // Watch for changes on relevant fields
                const watchFields = [
                    fieldNames.weight,
                    fieldNames.height,
                    fieldNames.measurementDate
                ];

                watchFields.forEach(fieldName => {
                    $(`[name="${fieldName}"]`).on('change blur', scheduleCalculation);
                });

                // Watch for sex radio button changes
                $(`input[name="${fieldNames.sex}"]`).on('change', scheduleCalculation);

                // Auto-calculate on page load if values exist
                setTimeout(autoCalculateCentiles, 500);
            });
        </script>
        <?php
    }
}
