#include <Arduino.h>
#include "esp_heap_caps.h"
#include "esp_log.h"
#include "epd_driver.h"
#include "epd_highlevel.h"
#include <driver/adc.h>
#include "esp_adc_cal.h"
#include "esp_sleep.h"
#include "Firasans.h"
#include <WiFi.h>
#include <HTTPClient.h>

#define BATT_PIN 36
#define WAVEFORM EPD_BUILTIN_WAVEFORM

/**
 * Upper most button on side of device. Used to setup as wakeup source to start from deepsleep.
 * Pinout here https://ae01.alicdn.com/kf/H133488d889bd4dd4942fbc1415e0deb1j.jpg
 */
gpio_num_t FIRST_BTN_PIN = GPIO_NUM_39;

// ambient temperature around device
int temperature = 20;
enum EpdDrawError err;

class Board
{
public:
    uint8_t *fb;
    EpdiyHighlevelState hl;
    int vref = 1100;

    void begin()
    {
        Serial.begin(115200);

        correct_adc_reference();

        // First setup epd to use later
        epd_init(EPD_OPTIONS_DEFAULT);
        hl = epd_hl_init(WAVEFORM);
        epd_set_rotation(EPD_ROT_LANDSCAPE);
        fb = epd_hl_get_framebuffer(&hl);
        // epd_clear();

        print_wakeup_reason();
    }

    void correct_adc_reference()
    {
        esp_adc_cal_characteristics_t adc_chars;
        esp_adc_cal_value_t val_type = esp_adc_cal_characterize(ADC_UNIT_1, ADC_ATTEN_DB_11, ADC_WIDTH_BIT_12, 1100, &adc_chars);
        if (val_type == ESP_ADC_CAL_VAL_EFUSE_VREF)
        {
            Serial.printf("eFuse Vref:%u mV", adc_chars.vref);
            vref = adc_chars.vref;
        }
    }

    double_t get_battery_percentage()
    {
        // When reading the battery voltage, POWER_EN must be turned on
        epd_poweron();
        delay(50);

        Serial.println(epd_ambient_temperature());

        uint16_t v = analogRead(BATT_PIN);
        Serial.print("Battery analogRead value is");
        Serial.println(v);
        double_t battery_voltage = ((double_t)v / 4095.0) * 2.0 * 3.3 * (vref / 1000.0);

        // Better formula needed I suppose
        // experimental super simple percent estimate no lookup anything just divide by 100
        double_t percent_experiment = ((battery_voltage - 3.7) / 0.5) * 100;

        // cap out battery at 100%
        // on charging it spikes higher
        if (percent_experiment > 100)
        {
            percent_experiment = 100;
        }

        String voltage = "Battery Voltage :" + String(battery_voltage) + "V which is around " + String(percent_experiment) + "%";
        Serial.println(voltage);

        epd_poweroff();
        delay(50);

        return percent_experiment;
    }

    void print_wakeup_reason()
    {
        esp_sleep_wakeup_cause_t wakeup_reason;
        wakeup_reason = esp_sleep_get_wakeup_cause();
        switch (wakeup_reason)
        {
        case ESP_SLEEP_WAKEUP_EXT0:
            Serial.println("Wakeup caused by external signal using RTC_IO");
            break;
        case ESP_SLEEP_WAKEUP_EXT1:
            Serial.println("Wakeup caused by external signal using RTC_CNTL");
            break;
        case ESP_SLEEP_WAKEUP_TIMER:
            Serial.println("Wakeup caused by timer");
            break;
        case ESP_SLEEP_WAKEUP_TOUCHPAD:
            Serial.println("Wakeup caused by touchpad");
            break;
        case ESP_SLEEP_WAKEUP_ULP:
            Serial.println("Wakeup caused by ULP program");
            break;
        default:
            Serial.printf("Wakeup was not caused by deep sleep: %d\n", wakeup_reason);
            break;
        }
    }

    void start_deep_sleep_with_wakeup_sources()
    {
        epd_poweroff();
        delay(400);
        esp_sleep_enable_ext0_wakeup(FIRST_BTN_PIN, 0);

        Serial.println("Sending device to deepsleep");
        esp_deep_sleep_start();
    }

    void setClock()
    {
        configTime(0, 0, "pool.ntp.org", "time.nist.gov");

        Serial.print(F("Waiting for NTP time sync: "));
        time_t nowSecs = time(nullptr);
        while (nowSecs < 8 * 3600 * 2)
        {
            delay(500);
            Serial.print(F("."));
            yield();
            nowSecs = time(nullptr);
        }

        Serial.println();
        struct tm timeinfo;
        gmtime_r(&nowSecs, &timeinfo);
        Serial.print(F("Current time: "));
        Serial.print(asctime(&timeinfo));
    };

    int getMinute()
    {
        struct tm timeinfo;
        getLocalTime(&timeinfo);
        return timeinfo.tm_min;
    }

    int getHour()
    {
        struct tm timeinfo;
        getLocalTime(&timeinfo);
        return timeinfo.tm_hour;
    }
};

Board board;

void setup()
{
    // init board stuff
    board.begin();
    // epd_clear();

    // connect to wifi
    WiFi.begin("your wifi", "password");
    while (WiFi.status() != WL_CONNECTED)
    {
        delay(500);
        Serial.print(".");
    }
    Serial.println("");
    Serial.println("WiFi connected");
    Serial.println("IP address: ");
    Serial.println(WiFi.localIP());

    // get the current date/time from ntp
    board.setClock();

    // get the screen image
    HTTPClient http;
    http.begin("http://www.vedelaar.nl/helium.php");
    http.setTimeout(60000);
    Serial.print("[HTTP] GET...\n");
    int httpCode = http.GET();
    if (httpCode > 0)
    {
        Serial.printf("[HTTP] GET... code: %d\n", httpCode);
        if (httpCode == HTTP_CODE_OK)
        {
            Serial.printf("[HTTP] GET... OK\n");

            int len = 259200;
            uint8_t buff[480] = {0};

            WiFiClient *stream = http.getStreamPtr();
            Serial.printf("Start reading\n");

            uint8_t *framebuffer = epd_hl_get_framebuffer(&board.hl);
            //int line = 0;
            int pix = 0;

            while (http.connected() && (len > 0 || len == -1))
            {
                // Serial.printf("Reading left to do: %d on line %d, on coords: %d, %d\n", len, line, pix/480, pix%480);
                //  get available data size
                size_t size = stream->available();

                if (size)
                {
                    // read up to 128 byte
                    int c = stream->readBytes(framebuffer, ((size > sizeof(buff)) ? sizeof(buff) : size));
                    len -= c;
                    framebuffer += c;
                    pix += c;
                }
                // delay(1);
            }

            Serial.println();
            Serial.print("[HTTP] connection closed or file end.\n");
        }
    }
    else
    {
        Serial.printf("[HTTP] GET... failed, error: %s\n", http.errorToString(httpCode).c_str());
    }

    http.end();

    // battery percentage
    int cursor_x = EPD_WIDTH - 30;
    int cursor_y = 20;
    EpdFontProperties font_props = epd_font_properties_default();
    font_props.flags = EPD_DRAW_ALIGN_CENTER;
    font_props.fg_color = 0xf;
    epd_write_string(&FiraSans_12, String(board.get_battery_percentage(), 0).c_str(), &cursor_x, &cursor_y, board.fb, &font_props);

    // Send to display
    epd_poweron();
    epd_hl_update_screen(&board.hl, MODE_GC16, temperature);
    delay(100);
    epd_poweroff();

    // Go to deepsleep for 10 minutes if it's daytime
    int endHr = 21;
    int startHr = 6;
    int xMinutes = 10;
    int timeToWaitMin;
    if (board.getHour() > startHr && board.getHour() < endHr) {
      timeToWaitMin = xMinutes - (board.getMinute() % xMinutes);
    } else {
        if (board.getHour() > startHr) {
            timeToWaitMin = (((23 - board.getHour()) + startHr) * 60) - board.getMinute();
        } else {
            timeToWaitMin = ((startHr - board.getHour()) * 60) - board.getMinute();
        }
    }
    int timeToWaitSec = timeToWaitMin * 60;
    Serial.println("Current time is " + String(board.getHour()) + ":" + String(board.getMinute()) + " and we refresh in " + String(timeToWaitMin) + " minutes (" + String(timeToWaitSec) + " sec)");
    delay(400);
    esp_sleep_enable_ext0_wakeup(FIRST_BTN_PIN, 0);
    ESP.deepSleep(1e6 * timeToWaitSec);

    // // Go to deepsleep until the whole hour
    // int timeToWaitMin = 60 - board.getMinute();
    // int timeToWaitSec = timeToWaitMin*60;
    // Serial.println("Current minute is "+String(board.getMinute())+" and we refresh in "+String(timeToWaitMin)+" minutes ("+String(timeToWaitSec)+" sec)");
    // delay(400);
    // esp_sleep_enable_ext0_wakeup(FIRST_BTN_PIN, 0);
    // ESP.deepSleep(1e6*timeToWaitSec);
}

void loop()
{
    delay(1000);
}
