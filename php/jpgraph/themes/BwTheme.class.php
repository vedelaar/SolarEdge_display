<?php

/**
* Vivid Theme class
*/
class BwTheme extends Theme 
{
    private $font_color       = '#FFFFFF';
    private $background_color = '#000000';
    private $axis_color       = '#FFFFFF';
    private $grid_color       = '#000000';

    function GetColorList() {
        return array(
            '#FFFFFF',
            '#FFFFFF',
            '#000000',
            '#000000',
            '#000000',
            '#000000',
            '#000000',
            '#000000',
            '#000000',
            '#000000',
            '#000000',
            '#000000',
        );
    }

    function SetupGraph($graph) {

        // graph
        /*
        $img = $graph->img;
        $height = $img->height;
        $graph->SetMargin($img->left_margin, $img->right_margin, $img->top_margin, $height * 0.25);
        */
        $graph->SetFrame(true);
        $graph->SetMarginColor('black');
        $graph->SetBackgroundGradient($this->background_color, '#000000', GRAD_HOR, BGRAD_PLOT);

        // legend
        $graph->legend->SetFrameWeight(0);
        $graph->legend->Pos(0.5, 0.85, 'center', 'top');
        $graph->legend->SetFillColor('white');
        $graph->legend->SetLayout(LEGEND_HOR);
        $graph->legend->SetColumns(3);
        $graph->legend->SetShadow(false);
        $graph->legend->SetMarkAbsSize(5);

        // xaxis
        $graph->xaxis->title->SetColor($this->font_color);  
        $graph->xaxis->SetColor($this->axis_color, $this->font_color);    
        $graph->xaxis->SetTickSide(SIDE_BOTTOM);
        $graph->xaxis->SetLabelMargin(10);

        // yaxis
        $graph->yaxis->title->SetColor($this->font_color);  
        $graph->yaxis->SetColor($this->axis_color, $this->font_color);    
        $graph->yaxis->SetTickSide(SIDE_LEFT);
        $graph->yaxis->SetLabelMargin(8);
        $graph->yaxis->HideLine();
        $graph->yaxis->HideTicks();
        $graph->xaxis->SetTitleMargin(15);

        // grid
        $graph->ygrid->SetColor($this->grid_color);
        $graph->ygrid->SetLineStyle('dotted');


        // font
        $graph->title->SetColor($this->font_color);
        $graph->subtitle->SetColor($this->font_color);
        $graph->subsubtitle->SetColor($this->font_color);

//        $graph->img->SetAntiAliasing();
    }


    function SetupPieGraph($graph) {

        // graph
        $graph->SetFrame(false);

        // legend
        $graph->legend->SetFillColor('white');

        $graph->legend->SetFrameWeight(0);
        $graph->legend->Pos(0.5, 0.80, 'center', 'top');
        $graph->legend->SetLayout(LEGEND_HOR);
        $graph->legend->SetColumns(4);

        $graph->legend->SetShadow(false);
        $graph->legend->SetMarkAbsSize(5);

        // title
        $graph->title->SetColor($this->font_color);
        $graph->subtitle->SetColor($this->font_color);
        $graph->subsubtitle->SetColor($this->font_color);

        $graph->SetAntiAliasing();
    }


    function PreStrokeApply($graph) {
        if ($graph->legend->HasItems()) {
            $img = $graph->img;
            $graph->SetMargin(
                $img->raw_left_margin, 
                $img->raw_right_margin, 
                $img->raw_top_margin, 
                is_numeric($img->raw_bottom_margin) ? $img->raw_bottom_margin : $img->height * 0.25
            );
        }
    }

    function ApplyPlot($plot) {

        switch (get_class($plot))
        { 
            case 'GroupBarPlot':
            {
                foreach ($plot->plots as $_plot) {
                    $this->ApplyPlot($_plot);
                }
                break;
            }

            case 'AccBarPlot':
            {
                foreach ($plot->plots as $_plot) {
                    $this->ApplyPlot($_plot);
                }
                break;
            }

            case 'BarPlot':
            {
                $plot->Clear();

                $color = $this->GetNextColor();
                $plot->SetColor($color);
                $plot->SetFillColor($color);
                $plot->SetShadow('red', 3, 4, false);
                break;
            }

            case 'LinePlot':
            {
                $plot->Clear();
                $plot->SetColor($this->GetNextColor().'@0.4');
                $plot->SetWeight(2);
//                $plot->SetBarCenter();
                break;
            }

            case 'PiePlot':
            {
                $plot->SetCenter(0.5, 0.45);
                $plot->ShowBorder(false);
                $plot->SetSliceColors($this->GetThemeColors());
                break;
            }

            case 'PiePlot3D':
            {
                $plot->SetSliceColors($this->GetThemeColors());
                break;
            }
    
            default:
            {
            }
        }
    }
}


?>